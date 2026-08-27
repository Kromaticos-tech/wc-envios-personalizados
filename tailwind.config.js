/** @type {import('tailwindcss').Config} */
module.exports = {
  // Solo escanea lo que el plugin realmente pinta en el checkout: así el CSS final queda purgado.
  content: ['./includes/**/*.php', './assets/js/**/*.js'],
  corePlugins: {
    preflight: false, // evita resetear estilos globales del tema del cliente (no es nuestra página completa)
  },
  theme: {
    extend: {},
  },
  plugins: [],
};
