(() => {
 const menu=document.getElementById('menuBtn'), sidebar=document.getElementById('sidebar'), overlay=document.getElementById('sidebarOverlay');
 const setOpen=(open)=>{if(!menu||!sidebar)return;sidebar.classList.toggle('open',open);overlay?.classList.toggle('show',open);menu.setAttribute('aria-expanded',String(open));};
 menu?.addEventListener('click',()=>setOpen(!sidebar.classList.contains('open')));overlay?.addEventListener('click',()=>setOpen(false));
 document.addEventListener('keydown',e=>{
   if(e.key==='Escape')setOpen(false);
   if(e.key==='/'&&!e.ctrlKey&&!e.metaKey&&!['INPUT','TEXTAREA','SELECT'].includes(document.activeElement?.tagName)){e.preventDefault();const field=document.querySelector('.quick-search input')||document.querySelector('.hero-search input');field?.focus();}
 });
 document.querySelectorAll('[data-copy-url]').forEach(b=>b.addEventListener('click',async()=>{
   const val=b.dataset.copyUrl;if(!val)return;
   try{await navigator.clipboard.writeText(val);b.textContent='Скопировано ✓';setTimeout(()=>b.textContent='Скопировать ссылку',2000)}catch{window.prompt('Скопируй ссылку:',val)}
 }));
})();
