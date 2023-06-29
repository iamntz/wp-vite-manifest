<?php

namespace iamntz\WpViteManifest\Assets;

class ManifestParser
{
    private array $parsed = [];

    private array $styles = [];

    public function __construct(private $manifest) {}

    public function getCss(string $entry): array
    {
        $this->parse(null, $entry);

        return $this->styles[$entry] ?? [];
    }

    private function parse(?string $entry, ?string $parent = null)
    {
        if ($entry && ($this->parsed[$entry] ?? false)) {
            return;
        }

        $entry ??= $parent;

        $this->styles[$parent] = array_merge( $this->manifest->{$entry}->css ?? [], $this->styles[$parent] ?? []);
        $this->parsed[$entry] = true;

        foreach (($this->manifest->{$entry}->imports ?? []) as $js) {
            $this->parse($js, $parent);
        }
    }
}