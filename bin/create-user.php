<?php
declare(strict_types=1);
require dirname(__DIR__) . '/src/bootstrap.php';
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$username=trim($argv[1]??''); $email=trim($argv[2]??'');
if ($username==='' || strlen($argv[3]??'')<12) { fwrite(STDERR,"Usage: php bin/create-user.php USERNAME EMAIL PASSWORD (password 12+ chars)\n"); exit(2); }
$q=db()->prepare('INSERT INTO portal_users (username,email,password_hash) VALUES (:username,:email,:hash)');
$q->execute(['username'=>$username,'email'=>$email!==''?$email:null,'hash'=>password_hash($argv[3],PASSWORD_DEFAULT)]);
fwrite(STDOUT,"Portal user created.\n");
