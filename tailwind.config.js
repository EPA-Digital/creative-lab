import defaultTheme from 'tailwindcss/defaultTheme';
import forms from '@tailwindcss/forms';

/** @type {import('tailwindcss').Config} */
export default {
    content: [
        './vendor/laravel/framework/src/Illuminate/Pagination/resources/views/*.blade.php',
        './storage/framework/views/*.php',
        './resources/views/**/*.blade.php',
        './resources/js/**/*.vue',
    ],

    darkMode: 'class',

    theme: {
        extend: {
            fontFamily: {
                sans: ['Figtree', ...defaultTheme.fontFamily.sans],
            },
            colors: {
                // Paleta del dashboard de análisis creativo -- oscuro
                // #150C24 + ámbar. No toca los colores default de Tailwind
                // (Breeze/auth siguen con su propia paleta).
                noche: {
                    DEFAULT: '#150C24',
                    50: '#F5F3F8',
                    100: '#E8E3EF',
                    200: '#C7BAD9',
                    300: '#9B84B8',
                    400: '#6B4F8F',
                    500: '#3D2A5C',
                    600: '#2A1D40',
                    700: '#1F1533',
                    800: '#150C24',
                    900: '#0D0718',
                    950: '#070410',
                },
                ambar: {
                    DEFAULT: '#F5A623',
                    50: '#FEF8ED',
                    100: '#FDECC8',
                    200: '#FBD98C',
                    300: '#F9C458',
                    400: '#F5A623',
                    500: '#E08A0E',
                    600: '#B8690A',
                    700: '#8F4E0C',
                    800: '#743E10',
                    900: '#623411',
                },
            },
        },
    },

    plugins: [forms],
};
