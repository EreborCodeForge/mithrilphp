<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>AppMarket - @yield('title')</title>
    
    <script src="<?php echo App\Common\Assets\Asset::urlFromPublic('/assets/js/vue.esm-browser.js'); ?>"></script>
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            darkMode: 'class',
            theme: {
                extend: {
                    colors: {
                        gray: {
                            50: '#f9fafb',
                            100: '#f3f4f6',
                            200: '#e5e7eb',
                            300: '#d1d5db',
                            400: '#9ca3af',
                            500: '#6b7280',
                            600: '#4b5563',
                            700: '#374151',
                            800: '#1f2937',
                            900: '#111827',
                        }
                    }
                }
            }
        }
    </script>
    
    <!-- Import Map for Vue -->
    <script type="importmap">
        {
            "imports": {
                "vue": "/assets/js/vue.esm-browser.js",
                "@components/": "/resources/js/components/"
            }
        }
    </script>
</head>
<body class="bg-gray-200 dark:bg-gray-900 transition-colors duration-300 flex items-center justify-center min-h-screen">
    <div id="app" class="w-full max-w-md p-6">
        @yield('content')
    </div>

    @yield('scripts')
</body>
</html>
