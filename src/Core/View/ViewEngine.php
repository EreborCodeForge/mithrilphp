<?php

declare(strict_types=1);

namespace App\Core\View;

class ViewEngine
{
    private static string $viewsPath = __DIR__ . '/../../../resources/views';
    private static string $cachePath = __DIR__ . '/../../../storage/views';
    
    // Context for rendering
    private static array $sections = [];
    private static ?string $layout = null; 

    public static function render(string $view, array $data = []): string
    {
        $viewFile = self::resolvePath($view);
        if (!file_exists($viewFile)) {
            throw new \Exception("View file not found: $viewFile");
        }

        $compiledFile = self::compile($viewFile);

        // Reset context for this render
        // Note: Recursive renders (partials) might need state management, 
        // but for extends/layout this simple static approach works if we are careful.
        // Actually, for proper nesting, we should use instance methods, but let's try static for simplicity first
        // or just handle scope carefully.
        
        ob_start();
        extract($data);
        include $compiledFile;
        $content = ob_get_clean();

        // If a layout was defined in the view (via @extends)
        if (self::$layout) {
            $layoutView = self::$layout;
            self::$layout = null; // Reset for the layout rendering
            return self::render($layoutView, array_merge($data, ['content' => $content]));
        }

        return $content;
    }

    private static function resolvePath(string $view): string
    {
        return self::$viewsPath . '/' . str_replace('.', '/', $view) . '.blade.php';
    }

    private static function compile(string $file): string
    {
        $hash = md5($file . filemtime($file));
        $compiledPath = self::$cachePath . '/' . $hash . '.php';

        if (file_exists($compiledPath) && filemtime($compiledPath) >= filemtime($file)) {
            return $compiledPath;
        }

        $content = file_get_contents($file);
        
        // Compile Directives

        // @{{ ... }} -> {{ ... }} (Vue escaping)
        $content = preg_replace('/@\{\{\s*(.+?)\s*\}\}/', '{{ $1 }}', $content);
        
        // {{ $var }} -> echo htmlspecialchars
        $content = preg_replace('/(?<!@)\{\{\s*(.+?)\s*\}\}/', '<?php echo htmlspecialchars($1 ?? \'\'); ?>', $content);
        
        // {!! $var !!} -> echo raw
        $content = preg_replace('/\{\!\!\s*(.+?)\s*\!\!\}/', '<?php echo $1 ?? \'\'; ?>', $content);

        // @extends('layout')
        $content = preg_replace('/@extends\(\s*[\'"](.+?)[\'"]\s*\)/', '<?php \App\Core\View\ViewEngine::extends(\'$1\'); ?>', $content);

        // @section('name')
        $content = preg_replace('/@section\(\s*[\'"](.+?)[\'"]\s*\)/', '<?php \App\Core\View\ViewEngine::startSection(\'$1\'); ?>', $content);

        // @endsection
        $content = str_replace('@endsection', '<?php \App\Core\View\ViewEngine::endSection(); ?>', $content);

        // @yield('name')
        $content = preg_replace('/@yield\(\s*[\'"](.+?)[\'"]\s*\)/', '<?php echo \App\Core\View\ViewEngine::yieldSection(\'$1\'); ?>', $content);

        // @foreach
        $content = preg_replace('/@foreach\s*\((.+?)\)/', '<?php foreach($1): ?>', $content);
        $content = str_replace('@endforeach', '<?php endforeach; ?>', $content);

        // @if
        $content = preg_replace('/@if\s*\((.+?)\)/', '<?php if($1): ?>', $content);
        $content = preg_replace('/@elseif\s*\((.+?)\)/', '<?php elseif($1): ?>', $content);
        $content = str_replace('@else', '<?php else: ?>', $content);
        $content = str_replace('@endif', '<?php endif; ?>', $content);

        file_put_contents($compiledPath, $content);

        return $compiledPath;
    }

    public static function extends(string $layout): void
    {
        self::$layout = $layout;
    }

    public static function startSection(string $name): void
    {
        self::$sections[$name] = '';
        ob_start();
    }

    public static function endSection(): void
    {
        $content = ob_get_clean();
        $keys = array_keys(self::$sections);
        $last = end($keys);
        if ($last) {
            self::$sections[$last] = $content;
        }
    }

    public static function yieldSection(string $name): string
    {
        return self::$sections[$name] ?? '';
    }
}
