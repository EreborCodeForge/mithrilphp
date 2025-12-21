<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login </title>
    <link href='https://unpkg.com/boxicons@2.0.9/css/boxicons.min.css' rel='stylesheet'>
    
    <script src="<?php echo App\Common\Assets\Asset::urlFromPublic('/assets/js/vue.esm-browser.js'); ?>"></script>
    <link rel="stylesheet" href="<?= '/resources/views/layouts/vue/style.css' ?>">
    <!-- Import Map for Vue -->
    <script type="importmap">
        {
            "imports": {
                "vue": "/assets/js/vue.esm-browser.js"
            }
        }
    </script>
</head>
<body class="bg-gray-200 dark:bg-gray-900 transition-colors duration-300">
    <div id="app">
        
    </div>

    <script type="module">
        import { createApp } from 'vue';
        import App from "<?= '/resources/views/layouts/vue/pages/login.js' ?>";
        
        createApp(App).mount('#app');
    </script>
</body>
</html>
