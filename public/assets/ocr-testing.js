(() => {
  'use strict';
  const form = document.getElementById('ocr-test-form');
  if (!form) return;
  const byId = id => document.getElementById(id);
  const mode = byId('ocr-mode');
  const fields = byId('ocr-file-fields');
  const results = byId('ocr-results');
  const error = byId('ocr-error');
  const progress = byId('ocr-progress');
  const labels = {name:'Full name', document_number:'NRIC / passport number', cidb_wording:'CIDB wording (informational)', cross_out:'Cross-out / cross-over', customer_signature:'Customer-added signature', embedded_signature:'Embedded signature (informational)', overall:'Overall verification'};
  const modes = {mykad:[['mykad_front','MyKad front'],['mykad_back','MyKad back']], passport:[['passport_image','Passport information page']], image:[['image_file','MyKad image']], form_pdf:[['document_pdf','One-page cancellation form']], mykad_pdf:[['document_pdf','MyKad PDF (one or two pages)']]};
  const node = (tag, text, className) => { const item = document.createElement(tag); if (text !== undefined) item.textContent = text; if (className) item.className = className; return item; };
  const title = text => String(text).replaceAll('_',' ').replace(/^./, char => char.toUpperCase());
  function clearResults() {
    results.hidden = true; error.hidden = true; progress.textContent = '';
    for (const id of ['ocr-name','ocr-number','ocr-verification','ocr-service-status','ocr-reason','ocr-metadata','ocr-checks','ocr-pages','ocr-json']) byId(id).replaceChildren();
  }
  function uploadFields() {
    clearResults(); fields.replaceChildren();
    for (const [name, label] of modes[mode.value]) {
      const input = node('input'); input.type = 'file'; input.name = name; input.id = 'ocr-upload-' + name; input.required = true;
      input.accept = name === 'document_pdf' ? 'application/pdf,.pdf' : 'image/jpeg,image/png,image/webp,.jpg,.jpeg,.png,.webp';
      const text = node('label', label); text.htmlFor = input.id;
      input.addEventListener('change', clearResults); fields.append(text, input);
    }
  }
  function showPage(page) {
    const section = node('section', undefined, 'ocr-page');
    section.append(node('h4', 'Page ' + page.page), node('p', 'Visual inspection: ' + title(page.visual_state || 'not_checked') + '. Reference: ' + title(page.reference_state || 'not_checked') + '.', 'ocr-muted'));
    if (page.visual_reason) section.append(node('p',page.visual_reason));
    for (const reason of page.cross_out_reasons || []) section.append(node('p', reason));
    const detections = page.detections || [];
    const ruleEvidence = detections.some(item => Array.isArray(item.line) && !Number.isFinite(item.score));
    // Presentation cap only: the service evaluates all candidates before deciding.
    const displayed = ruleEvidence ? detections.filter(item => item.display !== false)
      .sort((a,b) => Number(b.state === 'present') - Number(a.state === 'present')).slice(0,6) : detections;
    const hidden = detections.filter(item => !displayed.includes(item));
    if (ruleEvidence) section.append(node('p', `Showing ${displayed.length} meaningful stroke candidates; ${hidden.length} other candidates are available below. Numbers match the overlay.`, 'ocr-muted'));
    displayed.forEach((item,index) => {
      if (item.reason) section.append(node('p', `${ruleEvidence ? '#'+(index+1)+' ' : ''}${title(item.label)}: ${title(item.state || 'candidate')} (${item.method || 'detector'}). ${item.reason}`));
    });
    if (hidden.length) {
      const additional = node('details'); additional.className='ocr-other-candidates';
      additional.append(node('summary', `Other inspected candidates (${hidden.length})`));
      for (const item of hidden) additional.append(node('p', `${title(item.state || 'candidate')}: ${item.reason || 'Detector candidate.'}`));
      section.append(additional);
    }
    for (const reason of page.signature_reasons || []) section.append(node('p', reason));
    if (typeof page.preview === 'string' && /^data:image\/(jpeg|png);base64,/.test(page.preview)) {
      const canvas = node('canvas'); canvas.setAttribute('role','img'); canvas.setAttribute('aria-label','Page ' + page.page + ' with detected marks'); section.append(canvas);
      const image = new Image();
      image.onload = () => {
        canvas.width = image.width; canvas.height = image.height;
        const ctx = canvas.getContext('2d'); ctx.drawImage(image,0,0);
        const sx = canvas.width / page.width, sy = canvas.height / page.height;
        function box(rect, label, color, dashed) {
          if (!Array.isArray(rect) || rect.length !== 4 || !rect.every(Number.isFinite)) return;
          const [x1,y1,x2,y2] = rect; ctx.strokeStyle=color; ctx.lineWidth=2; ctx.setLineDash(dashed ? [6,4] : []);
          ctx.strokeRect(x1*sx,y1*sy,(x2-x1)*sx,(y2-y1)*sy); ctx.font='13px sans-serif';
          const y=Math.max(15,y1*sy); const w=ctx.measureText(label).width+8; ctx.fillStyle='white'; ctx.fillRect(x1*sx,y-15,w,17); ctx.fillStyle=color; ctx.fillText(label,x1*sx+4,y-2);
        }
        for (const [index,item] of displayed.entries()) {
          const color = item.state === 'uncertain' ? '#946000' : item.label === 'customer_signature' ? '#087a49' : item.label === 'embedded_signature' ? '#b43346' : '#1256a3';
          const evidence = Number.isFinite(item.score) ? Math.round(item.score*100) + '%' : title(item.state || 'candidate') + ' (rule evidence)';
          if (Array.isArray(item.line) && item.line.length === 4 && item.line.every(Number.isFinite)) {
            const [x1,y1,x2,y2] = item.line; ctx.strokeStyle=color; ctx.lineWidth=2; ctx.setLineDash([]); ctx.beginPath(); ctx.moveTo(x1*sx,y1*sy); ctx.lineTo(x2*sx,y2*sy); ctx.stroke();
            const label = '#'+(index+1)+' '+title(item.state || 'candidate');
            ctx.font='13px sans-serif'; const width=ctx.measureText(label).width+8;
            const x=Math.max(0,Math.min(canvas.width-width,x2*sx)); const y=Math.max(16,Math.min(canvas.height,y2*sy));
            ctx.fillStyle='white'; ctx.fillRect(x,y-15,width,17); ctx.fillStyle=color; ctx.fillText(label,x+4,y-2);
          } else box(item.box, title(item.label) + ' ' + evidence, color, false);
        }
        for (const rect of page.reference_regions || []) box(rect,'Expected embedded region','#7a3ba0',true);
      };
      image.src=page.preview;
    }
    const detail = node('details'); detail.append(node('summary','Recognised text and confidence'), node('pre',(page.ocr_lines || []).map(line => `${Math.round(line.confidence*100)}%  ${line.text}`).join('\n') || 'OCR text not available for this page.')); section.append(detail);
    byId('ocr-pages').append(section);
  }
  function show(body) {
    byId('ocr-name').textContent=body.extracted_name || 'Not extracted';
    byId('ocr-number').textContent=body.extracted_document_number || 'Not extracted';
    byId('ocr-verification').textContent=body.verified === true ? 'Verified' : 'Not verified';
    byId('ocr-service-status').textContent=body.status || 'Unavailable';
    byId('ocr-reason').textContent=body.message || '';
    const diagnostics = body.diagnostics;
    const notice=byId('ocr-diagnostic-notice'); notice.hidden=Boolean(diagnostics);
    notice.textContent='This service response did not include diagnostic evidence. Detection results cannot be inferred from the overall status.';
    if (diagnostics) {
      byId('ocr-metadata').textContent=`Processing: ${diagnostics.processing_ms} ms · Pages visually checked: ${diagnostics.pages_checked} · Visual method: ${diagnostics.model?.release_id || 'Unavailable / not checked'}`;
      for (const [key, check] of Object.entries(diagnostics.checks || {})) {
        const row=node('tr'); const state=node('td',title(check.state),'ocr-state'); state.dataset.state=check.state;
        const reason = check.reason + (check.evidence?.length ? ' ' + check.evidence.map(line => line.text).join(' | ') : '');
        row.append(node('th',labels[key] || title(key)),state,node('td',reason)); row.firstChild.scope='row'; byId('ocr-checks').append(row);
      }
      for (const page of diagnostics.pages || []) showPage(page);
    }
    // Hide base64 preview bodies in the text view; original page previews remain above.
    byId('ocr-json').textContent=JSON.stringify(body,(key,value) => ['preview','data_url'].includes(key) ? '[image shown separately]' : value,2);
    results.hidden=false; byId('ocr-result-title').focus();
  }
  mode.addEventListener('change',uploadFields);
  byId('ocr-clear').addEventListener('click',() => { form.reset(); uploadFields(); });
  form.addEventListener('submit',async event => {
    event.preventDefault(); if (!form.reportValidity()) return;
    clearResults(); const data=new FormData(form); const controls=[...form.querySelectorAll('button,input,select')];
    controls.forEach(item => item.disabled=true); form.setAttribute('aria-busy','true'); progress.textContent='Processing the submitted document…';
    try {
      const response=await fetch(form.action,{method:'POST',body:data,credentials:'same-origin',cache:'no-store'});
      const body=await response.json();
      if (body.error) throw new Error(body.error);
      if (typeof body.verified !== 'boolean') throw new Error('The OCR service returned an unexpected response.');
      show(body); progress.textContent=response.ok ? 'Processing complete.' : 'The service returned an error; available results are shown below.';
    } catch (failure) { error.textContent=failure.message || 'Unable to reach OCR. Try again later.'; error.hidden=false; progress.textContent=''; }
    finally { controls.forEach(item => item.disabled=false); form.removeAttribute('aria-busy'); }
  });
  uploadFields();
})();
