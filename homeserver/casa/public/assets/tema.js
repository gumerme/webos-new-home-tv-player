// Aplica o tema antes de desenhar a página (evita piscar). Arquivo próprio por causa da CSP (sem script embutido).
try {
  var t = localStorage.getItem('casa_tema');
  if (t === 'escuro' || (!t && matchMedia('(prefers-color-scheme: dark)').matches)) {
    document.documentElement.classList.add('tema-escuro');
  }
} catch (e) { /* sem armazenamento */ }
