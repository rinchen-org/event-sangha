(function () {
  const password = document.getElementById('password');
  const toggle = document.getElementById('toggle-password');
  if (!password || !toggle) return;

  toggle.addEventListener('click', function () {
    const showPassword = password.type === 'password';
    password.type = showPassword ? 'text' : 'password';
    toggle.textContent = showPassword ? 'Ocultar contraseña' : 'Mostrar contraseña';
  });
  toggle.hidden = false;
})();
