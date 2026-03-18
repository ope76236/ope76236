(function(){
  // маленькая "живая" анимация на наведении карточек
  document.querySelectorAll('.card').forEach(card => {
    card.addEventListener('mousemove', (e) => {
      const r = card.getBoundingClientRect();
      const x = (e.clientX - r.left) / r.width;
      const y = (e.clientY - r.top) / r.height;
      card.style.transform = `perspective(900px) rotateX(${(0.5-y)*2}deg) rotateY(${(x-0.5)*2}deg)`;
    });
    card.addEventListener('mouseleave', () => {
      card.style.transform = 'none';
    });
  });
})();