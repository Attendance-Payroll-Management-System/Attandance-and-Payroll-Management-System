/**@type {import('tailwindcss').Config}     */
module.exports = {
  darkMode: 'class',
  content: ["./**/*.{html,js,php}"],
  theme: {
    extend: {
      colors: {
        navy: {
          50: '#EFF6FF',
          100: '#DBEAFE',
          200: '#BFDBFE',
          300: '#93C5FD',
          400: '#60A5FA',
          500: '#1E3A8A',
          600: '#1E3A8A',
          700: '#1E3A8A',
          800: '#1E293B',
          900: '#0F172A',
          950: '#0B1120',
        },
      },
    },
  },
  plugins: [],
}
