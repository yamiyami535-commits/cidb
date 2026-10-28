document.addEventListener('DOMContentLoaded',()=>{
  document.querySelectorAll('[data-submit-form]').forEach(form=>{
    const inputs=[...form.querySelectorAll('input[required]:not([type=hidden])')];
    inputs.forEach(input=>input.addEventListener('blur',()=>validate(input)));
    function validate(input){const box=input.closest('.field'); const err=box.querySelector('.field-error'); let msg=input.value.trim()?'':`${input.labels[0].textContent.replace('*','').trim()} is required.`; if(!msg&&input.type==='email'&&!input.validity.valid) msg='Enter a valid email address.'; err.textContent=msg; input.setAttribute('aria-invalid',msg?'true':'false'); return !msg;}
    form.addEventListener('submit',event=>{let valid=true; inputs.forEach(input=>{if(!validate(input)) valid=false;}); if(!valid){event.preventDefault(); form.querySelector('[aria-invalid=true]')?.focus();return;} const button=form.querySelector('.submit-button');button.disabled=true;button.classList.add('loading');button.querySelector('.button-label').textContent='Submitting request';});
  });
  document.querySelectorAll('.notice').forEach(n=>n.classList.add('visible'));

  // Poll only the specific request represented by each existing result area.
  // Keep this before the reduced-motion guard: polling is functional, not animation.
  const statusLabels={processing:'In progress',pending:'In progress',success:'Success',failed:'Failed'};
  const polling=new Map();
  const startPolling=(root=document)=>root.querySelectorAll('[data-request-id][data-request-complete="false"]').forEach(container=>{
    if(polling.has(container)) return;
    const requestId=container.dataset.requestId;
    let retryDelay=3000, timer, controller, stopped=false;
    const stop=()=>{stopped=true;clearTimeout(timer);controller?.abort();polling.delete(container);};
    polling.set(container,stop);
    const poll=async()=>{
      if(stopped || container.isConnected===false){stop();return;}
      controller=new AbortController();
      const timeout=setTimeout(()=>controller.abort(),15000);
      try {
        const response=await fetch(`/request-status/${encodeURIComponent(requestId)}`,{credentials:'same-origin',cache:'no-store',signal:controller.signal});
        if(stopped) return;
        if([401,403,404].includes(response.status)){stop();return;}
        if(!response.ok) throw new Error('Request status unavailable');
        const result=await response.json();
        if(stopped) return;
        if(result.id!==requestId || !Object.prototype.hasOwnProperty.call(statusLabels,result.status) || typeof result.complete!=='boolean' || typeof result.message!=='string') throw new Error('Invalid request status');
        container.querySelectorAll('[data-request-message]').forEach(element=>{element.textContent=result.message;});
        container.querySelectorAll('[data-request-status], [data-request-status-dot]').forEach(element=>{
          element.classList.remove(...Object.keys(statusLabels));
          element.classList.add(result.status);
          if(element.hasAttribute('data-request-status')) element.textContent=statusLabels[result.status];
        });
        container.querySelectorAll('[data-request-completed]').forEach(element=>{element.textContent=result.completed_at;});
        container.dataset.requestComplete=String(result.complete);
        if(result.complete){stop();return;}
        retryDelay=3000;
      } catch(error) {
        // A transient failure must not turn an accepted submission into a failure.
        retryDelay=Math.min(retryDelay*2,30000);
      } finally {
        clearTimeout(timeout);
      }
      if(!stopped) timer=setTimeout(poll,retryDelay);
    };
    poll();
  });
  startPolling();

  const historyRoot=document.querySelector('[data-request-history]');
  if(historyRoot){
    // Background requests retain server-side filtering; the address bar stays clean.
    if(window.location.search) window.history.replaceState(window.history.state,'','/request-history');
    let navigation, sequence=0;
    historyRoot.addEventListener('click',async event=>{
      const link=event.target.closest('[data-history-link]');
      if(!link || !historyRoot.contains(link)) return;
      event.preventDefault();
      const requestSequence=++sequence;
      navigation?.abort();
      const controller=new AbortController(); navigation=controller;
      const timeout=setTimeout(()=>controller.abort(),15000);
      const feedback=historyRoot.querySelector('[data-history-feedback]');
      feedback.textContent='Loading requests…';feedback.hidden=false;
      historyRoot.setAttribute('aria-busy','true');
      try {
        const response=await fetch(link.href,{credentials:'same-origin',cache:'no-store',signal:controller.signal});
        if(response.redirected || [401,403].includes(response.status)) throw new Error('Your session has expired. Please sign in again.');
        if(!response.ok) throw new Error('Unable to load requests. Please try again.');
        const html=await response.text();
        if(requestSequence!==sequence) return;
        const next=new DOMParser().parseFromString(html,'text/html').querySelector('[data-request-history]');
        if(!next || next.dataset.historySource!==link.dataset.historySource) throw new Error('Unable to load requests. Please try again.');
        // Cancel timers and in-flight polls before removing their request rows.
        for(const [container,stop] of polling) if(historyRoot.contains(container)) stop();
        historyRoot.replaceChildren(...next.childNodes);
        historyRoot.dataset.historySource=next.dataset.historySource;
        historyRoot.dataset.historyPage=next.dataset.historyPage;
        startPolling(historyRoot);
        const focusTarget=link.closest('.history-filters')
          ? historyRoot.querySelector('.history-filters [aria-current="page"]')
          : historyRoot.querySelector('.history-pagination [aria-current="page"]') || historyRoot.querySelector('.history-filters [aria-current="page"]');
        focusTarget?.focus({preventScroll:true});
      } catch(error) {
        if(requestSequence===sequence){feedback.textContent=error.name==='AbortError'?'Request timed out. Please try again.':error.message;feedback.hidden=false;}
      } finally {
        clearTimeout(timeout);
        if(requestSequence===sequence) historyRoot.removeAttribute('aria-busy');
      }
    });
  }

  const reducedMotion=window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  if(reducedMotion) return;

  const art=document.querySelector('.login-art');
  const orbitDot=art?.querySelector('.dot-two');
  const innerOrbitDot=art?.querySelector('.dot-one');
  if(art&&orbitDot){
    art.classList.add('motion-ready');
    let angle=0.85,innerAngle=2.6,last=performance.now(),paused=false;
    const orbitTick=now=>{
      const elapsed=Math.min(40,now-last); last=now;
      if(!paused){
        angle=(angle+elapsed*0.00035)%(Math.PI*2);
        innerAngle=(innerAngle-elapsed*0.00035)%(Math.PI*2);
        const width=art.offsetWidth,height=art.offsetHeight;
        const outerRadiusX=width/2,outerRadiusY=(height-48)/2,outerRotation=-24*Math.PI/180;
        const outerX=Math.cos(angle)*outerRadiusX,outerY=Math.sin(angle)*outerRadiusY;
        orbitDot.style.transform=`translate3d(${(outerX*Math.cos(outerRotation)-outerY*Math.sin(outerRotation)).toFixed(2)}px,${(outerX*Math.sin(outerRotation)+outerY*Math.cos(outerRotation)).toFixed(2)}px,0)`;
        if(innerOrbitDot){
          const innerRadiusX=(width-104)/2,innerRadiusY=height/2,innerRotation=54*Math.PI/180;
          const innerX=Math.cos(innerAngle)*innerRadiusX,innerY=Math.sin(innerAngle)*innerRadiusY;
          innerOrbitDot.style.transform=`translate3d(${(innerX*Math.cos(innerRotation)-innerY*Math.sin(innerRotation)).toFixed(2)}px,${(innerX*Math.sin(innerRotation)+innerY*Math.cos(innerRotation)).toFixed(2)}px,0)`;
        }
      }
      requestAnimationFrame(orbitTick);
    };
    art.addEventListener('pointerenter',()=>{paused=true;});
    art.addEventListener('pointerleave',()=>{paused=false;last=performance.now();});
    requestAnimationFrame(orbitTick);
  }

  const card=document.querySelector('.visual-card');
  if(card){
    const state={x:0,y:0,rx:0,ry:0,glare:0,targetX:0,targetY:0,targetRx:0,targetRy:0,targetGlare:0,running:false};
    const render=()=>{
      state.x+=(state.targetX-state.x)*.12; state.y+=(state.targetY-state.y)*.12;
      state.rx+=(state.targetRx-state.rx)*.12; state.ry+=(state.targetRy-state.ry)*.12; state.glare+=(state.targetGlare-state.glare)*.12;
      card.style.transform=`perspective(700px) translate3d(${state.x.toFixed(2)}px,${state.y.toFixed(2)}px,0) rotateX(${state.rx.toFixed(2)}deg) rotateY(${state.ry.toFixed(2)}deg) rotate(3deg)`;
      card.style.setProperty('--glare-opacity',state.glare.toFixed(3));
      if(state.running){
        if(state.targetGlare===0&&Math.abs(state.x)<.02&&Math.abs(state.y)<.02&&Math.abs(state.rx)<.02&&Math.abs(state.ry)<.02){state.running=false; card.style.transform='rotate(3deg)'; card.style.setProperty('--glare-opacity','0'); return;}
        requestAnimationFrame(render);
      }
    };
    card.addEventListener('pointerenter',event=>{if(event.pointerType==='touch') return; state.running=true; state.targetGlare=1; requestAnimationFrame(render);});
    card.addEventListener('pointermove',event=>{
      if(event.pointerType==='touch') return;
      const rect=card.getBoundingClientRect(),x=Math.max(0,Math.min(1,(event.clientX-rect.left)/rect.width)),y=Math.max(0,Math.min(1,(event.clientY-rect.top)/rect.height));
      state.targetX=(x-.5)*12; state.targetY=(y-.5)*10; state.targetRx=(.5-y)*3; state.targetRy=(x-.5)*4; state.targetGlare=1; state.running=true;
      card.style.setProperty('--glare-x',`${(x*100).toFixed(1)}%`); card.style.setProperty('--glare-y',`${(y*100).toFixed(1)}%`);
    });
    card.addEventListener('pointerleave',event=>{if(event.pointerType==='touch') return; state.targetX=0; state.targetY=0; state.targetRx=0; state.targetRy=0; state.targetGlare=0; state.running=true; requestAnimationFrame(render);});
  }
});
