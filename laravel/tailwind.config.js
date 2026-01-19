import defaultTheme from 'tailwindcss/defaultTheme';

/** @type {import('tailwindcss').Config} */
export default {
    content: [
        './vendor/laravel/framework/src/Illuminate/Pagination/resources/views/*.blade.php',
        './storage/framework/views/*.php',
        './resources/**/*.blade.php',
        './resources/**/*.js',
        './resources/**/*.vue',
    ],
    theme: {
        extend: {
            fontFamily: {
                sans: ['Roboto', ...defaultTheme.fontFamily.sans],
            },
            colors: {
                // Primary | Cyan #4AF5FF
                primary: {
                    50: '#e4feff',
                    100: '#dbfdff',
                    200: '#d2fdff',
                    300: '#b7fbff',
                    400: '#80f8ff',
                    500: '#4AF5FF',
                    600: '#43dde6',
                    700: '#38b8bf',
                    800: '#2c9399',
                    900: '#24787d',
                    DEFAULT: '#4AF5FF',
                },
                // Secondary | Lime green #80FF00
                secondary: {
                    50: '#ecffd9',
                    100: '#e6ffcc',
                    200: '#dfffbf',
                    300: '#ccff99',
                    400: '#a6ff4d',
                    500: '#80FF00',
                    600: '#73e600',
                    700: '#60bf00',
                    800: '#4d9900',
                    900: '#3f7d00',
                    DEFAULT: '#80FF00',
                },
                // Tertiary | Dark navy #15354D
                tertiary: {
                    50: '#dce1e4',
                    100: '#d0d7db',
                    200: '#c5cdd3',
                    300: '#a1aeb8',
                    400: '#5b7282',
                    500: '#15354D',
                    600: '#133045',
                    700: '#10283a',
                    800: '#0d202e',
                    900: '#0a1a26',
                    DEFAULT: '#15354D',
                },
                // Success | Lime #84cc16
                success: {
                    50: '#edf7dc',
                    100: '#e6f5d0',
                    200: '#e0f2c5',
                    300: '#ceeba2',
                    400: '#a9db5c',
                    500: '#84cc16',
                    600: '#77b814',
                    700: '#639911',
                    800: '#4f7a0d',
                    900: '#41640b',
                    DEFAULT: '#84cc16',
                },
                // Warning | Yellow #EAB308
                warning: {
                    50: '#fcf4da',
                    100: '#fbf0ce',
                    200: '#faecc1',
                    300: '#f7e19c',
                    400: '#f0ca52',
                    500: '#EAB308',
                    600: '#d3a107',
                    700: '#b08606',
                    800: '#8c6b05',
                    900: '#735804',
                    DEFAULT: '#EAB308',
                },
                // Error | Red #FF4A4A
                error: {
                    50: '#ffe4e4',
                    100: '#ffdbdb',
                    200: '#ffd2d2',
                    300: '#ffb7b7',
                    400: '#ff8080',
                    500: '#FF4A4A',
                    600: '#e64343',
                    700: '#bf3838',
                    800: '#992c2c',
                    900: '#7d2424',
                    DEFAULT: '#FF4A4A',
                },
                // Surface | Off-white #F6F8F8
                surface: {
                    50: '#fefefe',
                    100: '#fdfefe',
                    200: '#fdfdfd',
                    300: '#fbfcfc',
                    400: '#f9fafa',
                    500: '#F6F8F8',
                    600: '#dddfdf',
                    700: '#b9baba',
                    800: '#949595',
                    900: '#797a7a',
                    DEFAULT: '#F6F8F8',
                },
            },
        },
    },
    plugins: [],
};
