'use strict';
(() => {
  const header=document.querySelector('[data-topbar]');
  const progress=document.querySelector('[data-scroll-progress]');
  const menu=document.querySelector('[data-nav]');
  const toggle=document.querySelector('[data-menu-toggle]');
  let scheduled=false;
  const paint=()=>{
    const top=window.scrollY || document.documentElement.scrollTop;
    if(header)header.classList.toggle('scrolled',top>45);
    const scrollable=document.documentElement.scrollHeight-window.innerHeight;
    if(progress)progress.style.width=(scrollable>0?Math.min(100,top/scrollable*100):0)+'%';
    scheduled=false;
  };
  window.addEventListener('scroll',()=>{if(!scheduled){scheduled=true;window.requestAnimationFrame(paint)}},{passive:true});
  paint();
  if(toggle&&menu){
    const close=()=>{menu.classList.remove('open');document.body.classList.remove('menu-open');toggle.setAttribute('aria-expanded','false');toggle.setAttribute('aria-label','Открыть меню')};
    toggle.addEventListener('click',()=>{if(menu.classList.contains('open'))close();else{menu.classList.add('open');document.body.classList.add('menu-open');toggle.setAttribute('aria-expanded','true');toggle.setAttribute('aria-label','Закрыть меню')}});
    menu.querySelectorAll('a').forEach(link=>link.addEventListener('click',close));
    document.addEventListener('keydown',e=>{if(e.key==='Escape')close()});
    window.addEventListener('resize',()=>{if(window.innerWidth>1020)close()});
  }
  document.querySelectorAll('[data-copy]').forEach(btn=>{
    btn.addEventListener('click',async()=>{
      const value=btn.dataset.copy||'';
      if(!value)return;
      const before=btn.textContent;
      try{await navigator.clipboard.writeText(value);btn.textContent='СКОПИРОВАНО ✓';}
      catch(e){btn.textContent='СКОПИРУЙТЕ АДРЕС';}
      window.setTimeout(()=>{btn.textContent=before},1800);
    });
  });
})();
