<?php

/**
 * A page that uses <Dialog> or <DialogContent> without importing them relies on the global
 * registration in app.js. Take that away and nothing fails: Vue renders the browser's native
 * <dialog>, so the form opens inline under the page with no window around it. That went
 * unnoticed for a year on over thirty pages, which is what this guards.
 */
it('registers every Dialog component a page uses without importing it', function () {
    $root = dirname(__DIR__, 2).'/resources/js';
    $appJs = file_get_contents($root.'/app.js');

    $family = ['Dialog', 'DialogContent', 'DialogHeader', 'DialogTitle', 'DialogDescription', 'DialogFooter', 'DialogTrigger', 'DialogClose', 'DialogScrollContent'];
    $unresolved = [];

    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));

    foreach ($files as $file) {
        if ($file->getExtension() !== 'vue') {
            continue;
        }

        $source = file_get_contents($file->getPathname());
        $split = strpos($source, '<template>');

        if ($split === false) {
            continue;
        }

        // A commented-out dialog uses nothing.
        $template = preg_replace('/<!--.*?-->/s', '', substr($source, $split));
        $script = substr($source, 0, $split);

        foreach ($family as $tag) {
            $used = preg_match('/<'.$tag.'[\s>\/]/', $template) === 1;
            $imported = preg_match('/\b'.$tag.'\b/', $script) === 1;
            $registered = str_contains($appJs, '.component("'.$tag.'"');

            if ($used && ! $imported && ! $registered) {
                $unresolved[] = $tag.' in '.$file->getFilename();
            }
        }
    }

    expect($unresolved)->toBe([]);
});

/**
 * A modal turns pointer events off for the whole page and keeps focus inside itself. A PrimeVue
 * picker opens its list on the page body by default, so inside a modal the list shows but cannot
 * be clicked, and its search box loses what is typed. append-to="self" keeps the list in the modal.
 */
it('keeps the list of every PrimeVue picker inside a Dialog within it', function () {
    $root = dirname(__DIR__, 2).'/resources/js';
    $outside = [];

    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));

    foreach ($files as $file) {
        if ($file->getExtension() !== 'vue') {
            continue;
        }

        $source = file_get_contents($file->getPathname());
        $split = strpos($source, '<template>');

        if ($split === false) {
            continue;
        }

        $script = substr($source, 0, $split);
        $template = preg_replace('/<!--.*?-->/s', '', substr($source, $split));

        preg_match_all('/<DialogContent\b.*?<\/DialogContent>/s', $template, $dialogs);

        foreach ($dialogs[0] as $dialog) {
            preg_match_all('/<(Select|MultiSelect|DatePicker|AutoComplete)(\s[^>]*?)?\/?>/s', $dialog, $pickers, PREG_SET_ORDER);

            foreach ($pickers as $picker) {
                // A Select imported from anywhere but PrimeVue (the radix one) brings its own layer.
                $notPrimeVue = preg_match('/import\s+(?:\{[^}]*\b'.$picker[1].'\b[^}]*\}|'.$picker[1].')\s+from\s+[\'"](?!primevue)/', $script) === 1;
                $staysInside = preg_match('/append-to|appendTo/', $picker[0]) === 1;

                if (! $notPrimeVue && ! $staysInside) {
                    $outside[] = '<'.$picker[1].'> in '.$file->getFilename();
                }
            }
        }
    }

    expect($outside)->toBe([]);
});
