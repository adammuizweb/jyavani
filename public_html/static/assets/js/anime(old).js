document.addEventListener("DOMContentLoaded", () => {
  const observer = new IntersectionObserver(entries => {
    entries.forEach(entry => {
      if (entry.isIntersecting) {
        entry.target.classList.add("show");
        observer.unobserve(entry.target); // hanya animasi sekali
      }
    });
  }, { threshold: 0.2 }); // aktif saat 20% section terlihat

  document.querySelectorAll('.fade-up').forEach(el => observer.observe(el));
});
