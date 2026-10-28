<?php
declare(strict_types=1);
if (PHP_SAPI === 'cli-server') { $static = realpath(__DIR__ . parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH)); if ($static && str_starts_with($static, __DIR__ . DIRECTORY_SEPARATOR) && is_file($static)) return false; }
require dirname(__DIR__) . '/src/bootstrap.php';
require dirname(__DIR__) . '/src/RpaClient.php';
require dirname(__DIR__) . '/src/RpaSubmission.php';
require dirname(__DIR__) . '/src/request-result.php';
require dirname(__DIR__) . '/src/RequestHistory.php';

$https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
session_name('cidb_portal');
session_set_cookie_params(['httponly'=>true,'secure'=>filter_var(env_value('SESSION_SECURE_COOKIE',(string)$https),FILTER_VALIDATE_BOOL),'samesite'=>'Lax','path'=>'/']);
session_start();
header('X-Content-Type-Options: nosniff'); header('X-Frame-Options: DENY'); header('Referrer-Policy: strict-origin-when-cross-origin');
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/'; $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

try {
    if ($path === '/logout' && $method === 'POST') { if (!csrf_valid()) { http_response_code(419); exit('Session expired.'); } $_SESSION=[]; session_regenerate_id(true); flash('success','You have been signed out.'); redirect('/login'); }
    if ($path === '/login') {
        if (current_user()) redirect('/');
        $error = null;
        if ($method === 'POST') {
            if (!csrf_valid()) $error='Your session expired. Refresh the page and try again.';
            else {
                $username=trim((string)($_POST['username']??'')); $password=(string)($_POST['password']??'');
                if ($username==='' || $password==='') $error='Enter your username and password.';
                else { $q=db()->prepare('SELECT id, username, password_hash FROM portal_users WHERE (lower(username)=lower(:username) OR lower(email)=lower(:username)) AND is_active=true LIMIT 1'); $q->execute(['username'=>$username]); $user=$q->fetch();
                    if ($user && password_verify($password,$user['password_hash'])) { session_regenerate_id(true); $_SESSION['user']=['id'=>$user['id'],'username'=>$user['username']]; csrf_token(); redirect('/'); }
                    $error='Those sign-in details were not recognised.';
                }
            }
        }
        render('login',['error'=>$error,'flash'=>take_flash()]); exit;
    }
    if (preg_match('#^/request-status/([a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12})$#i', $path, $matches) && $method === 'GET') {
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        $user=current_user();
        session_write_close();
        if (!$user) { http_response_code(401); echo json_encode(['error'=>'Session expired.']); exit; }
        try {
            $request=(new RequestHistory(db()))->find($user['id'],$matches[1]);
            if (!$request) { http_response_code(404); echo json_encode(['error'=>'Request not found.']); exit; }
            echo json_encode(request_result($request), JSON_THROW_ON_ERROR);
        } catch (Throwable $e) {
            error_log('Portal request polling failed: '.$e->getMessage());
            http_response_code(500); echo json_encode(['error'=>'Unable to check the request right now.']);
        }
        exit;
    }
    require_login();
    if ($path === '/request-history' && $method === 'GET') {
        $source=is_string($_GET['source']??null) && in_array($_GET['source'],['all','form','email'],true) ? $_GET['source'] : 'all';
        $page=is_string($_GET['page']??null) ? filter_var($_GET['page'],FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]) : false;
        $history=(new RequestHistory(db()))->paginate(current_user()['id'],$source,$page?:1);
        render('request-history',$history+['source'=>$source]); exit;
    }
    if (preg_match('#^/request-history/([a-f0-9-]{36})$#i', $path, $matches) && $method === 'GET') {
        $request=(new RequestHistory(db()))->find(current_user()['id'],$matches[1]);
        if (!$request) { http_response_code(404); render('not-found'); exit; }
        render('request-details',['request'=>$request]); exit;
    }
    if ($path === '/submit' && $method === 'POST') {
        if (!csrf_valid()) { flash('error','Your session expired. Please refresh and submit again.'); redirect('/'); }
        $fields=['name'=>'Name','id_number'=>'ID Number','email'=>'Email','crm'=>'CRM']; $input=[]; $errors=[];
        foreach ($fields as $key=>$label) { $v=trim((string)($_POST[$key]??'')); $maxLength=$key==='name'?200:($key==='id_number'?40:($key==='email'?254:120)); if ($v==='') $errors[$key]="{$label} is required."; elseif (mb_strlen($v)>$maxLength) $errors[$key]="{$label} is too long."; elseif ($key==='email' && filter_var($v,FILTER_VALIDATE_EMAIL)===false) $errors[$key]='Enter a valid email address.'; $input[$key]=$v; }
        $language=(string)($_POST['language']??''); if (!in_array($language,['en','ms'],true)) $language='ms'; $input['language']=$language;
        if ($errors) { $_SESSION['form_errors']=$errors; $_SESSION['form_values']=$input; redirect('/'); }
        $submissionKey=(string)($_POST['submission_key']??'');
        if (!preg_match('/^[a-f0-9-]{36}$/i',$submissionKey)) { flash('error','This form expired. Please refresh and submit again.'); redirect('/'); }
        $pdo=db(); $check=$pdo->prepare('SELECT id,status,rpa_reference_id FROM portal_requests WHERE user_id=:uid AND submission_key=:key'); $check->execute(['uid'=>current_user()['id'],'key'=>$submissionKey]); $existing=$check->fetch();
        if ($existing) { $_SESSION['submission_key']=new_uuid(); flash('success','This request was already submitted. Reference: '.$existing['id']); redirect('/'); }
        $client=new RpaClient(); $payload=$client->payload($input); $id=null;
        try {
            $q=$pdo->prepare("INSERT INTO portal_requests (user_id,submission_key,applicant_name,id_number,applicant_email,crim,rpa_request_payload,status) VALUES (:uid,:key,:name,:idno,:email,:crm,CAST(:payload AS jsonb),'processing') RETURNING id");
            $q->execute(['uid'=>current_user()['id'],'key'=>$submissionKey,'name'=>$input['name'],'idno'=>$input['id_number'],'email'=>$input['email'],'crm'=>$input['crm'],'payload'=>json_encode($payload,JSON_THROW_ON_ERROR)]); $id=$q->fetchColumn();
            $response=$client->send($payload); $normalized=$client->normalize($response);
            RpaSubmission::record($pdo,$id,$response,$normalized);
            $_SESSION['submission_key']=new_uuid();
            flash($normalized['error_code']?'error':'success','Request '.$id.' — '.($normalized['error_code']?'Submission needs review.':'Submitted. Waiting for the RPA status update.'));
        } catch (Throwable $e) {
            error_log('Portal request processing failed: '.$e->getMessage());
            if ($id) { try { $q=$pdo->prepare("UPDATE portal_requests SET error_code='PROCESSING_ERROR',error_detail='Request processing failed',updated_at=now() WHERE id=:id"); $q->execute(['id'=>$id]); } catch (Throwable $ignored) {} }
            flash('error','We could not process your request at this time. Please try again later.');
            $_SESSION['submission_key']=new_uuid();
        }
        redirect('/');
    }
    if ($path !== '/' && $path !== '/index.php') { http_response_code(404); render('not-found'); exit; }
    $requests=(new RequestHistory(db()))->list(current_user()['id'],'all',5);
    $errors=$_SESSION['form_errors']??[]; $values=$_SESSION['form_values']??[]; unset($_SESSION['form_errors'],$_SESSION['form_values']);
    render('home',['flash'=>take_flash(),'requests'=>$requests,'errors'=>$errors,'values'=>$values]);
} catch (Throwable $e) { error_log('Portal application error: '.$e->getMessage()); http_response_code(500); render('error'); }
