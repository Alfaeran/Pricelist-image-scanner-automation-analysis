import defaultTheme from 'tailwindcss/defaultTheme';
import forms from '@tailwindcss/forms';

/** @type {import('tailwindcss').Config} */
export default {
    darkMode: 'class',
    content: [
        './vendor/laravel/framework/src/Illuminate/Pagination/resources/views/*.blade.php',
        './storage/framework/views/*.php',
        './resources/views/**/*.blade.php',
        './resources/js/**/*.vue',
    ],

    theme: {
        extend: {
            fontFamily: {
                sans: ['Ooredoo', ...defaultTheme.fontFamily.sans],
            },
            colors: {
                theme: {
                    page: 'var(--bg-page)',
                    surface: 'var(--bg-surface)',
                    secondary: 'var(--bg-secondary)',
                    elevated: 'var(--bg-elevated)',
                    text: {
                        primary: 'var(--text-primary)',
                        secondary: 'var(--text-secondary)',
                        muted: 'var(--text-muted)',
                    },
                    border: {
                        DEFAULT: 'var(--border-default)',
                        subtle: 'var(--border-subtle)',
                    },
                    brand: {
                        primary: 'var(--brand-primary)',
                        secondary: 'var(--brand-secondary)',
                        accent: 'var(--brand-accent)',
                    },
                    semantic: {
                        success: 'var(--semantic-success)',
                        warning: 'var(--semantic-warning)',
                        danger: 'var(--semantic-danger)',
                        info: 'var(--semantic-info)',
                    }
                }
            },
        },
    },

    plugins: [
        forms,
        require('@tailwindcss/typography'),
    ],
};
