import defaultTheme from 'tailwindcss/defaultTheme';
import forms from '@tailwindcss/forms';

/** @type {import('tailwindcss').Config} */
export default {
    content: [
        './vendor/laravel/framework/src/Illuminate/Pagination/resources/views/*.blade.php',
        './storage/framework/views/*.php',
        './resources/views/**/*.blade.php',
    ],

    theme: {
        extend: {
            fontFamily: {
                sans: ['Cairo', 'Work Sans', ...defaultTheme.fontFamily.sans],
            },
            colors: {
                brand: {
                    50:  '#fff8f0',
                    100: '#feecd4',
                    200: '#fcd4a8',
                    300: '#f9b272',
                    400: '#f58440',
                    500: '#e8780a',
                    600: '#c96608',
                    700: '#a55208',
                    800: '#83400c',
                    900: '#6b360e',
                    950: '#391a04',
                },
                dark: {
                    50:  '#f4f4f5',
                    100: '#e4e4e7',
                    200: '#c9c9cf',
                    300: '#a1a1ab',
                    400: '#71717e',
                    500: '#52525f',
                    600: '#3f3f4a',
                    700: '#27272f',
                    800: '#1a1a20',
                    900: '#111116',
                    950: '#08080a',
                },
            },
        },
    },

    plugins: [forms],
};
