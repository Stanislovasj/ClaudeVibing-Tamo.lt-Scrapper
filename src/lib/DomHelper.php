<?php

/**
 * Pagalbinės DOM funkcijos, imituojančios Python BeautifulSoup elgesį,
 * kurio PHP DOMDocument/DOMXPath neturi "iš dėžutės" (find_next, .next, class_="a b").
 */
final class DomHelper
{
    public static function loadHtml(string $html): DOMDocument
    {
        $doc = new DOMDocument();
        $prevErrors = libxml_use_internal_errors(true);
        // priverčiame UTF-8, kad DOMDocument nemangintų lietuviškų raidžių
        $doc->loadHTML('<?xml encoding="utf-8" ?>' . $html, LIBXML_NOERROR | LIBXML_NOWARNING);
        libxml_clear_errors();
        libxml_use_internal_errors($prevErrors);
        return $doc;
    }

    public static function xpath(DOMDocument $doc): DOMXPath
    {
        return new DOMXPath($doc);
    }

    /** trim($node->textContent), atitinka bs4 .text.strip() */
    public static function text(?DOMNode $node): string
    {
        if ($node === null) {
            return '';
        }
        return trim($node->textContent);
    }

    /** atitinka bs4 .text.replace("\n", "").strip() */
    public static function textNoNewline(?DOMNode $node): string
    {
        if ($node === null) {
            return '';
        }
        return trim(str_replace(["\r", "\n"], '', $node->textContent));
    }

    /** XPath sąlyga elementui, turinčiam VISAS nurodytas CSS klases (nepriklausomai nuo eiliškumo). */
    public static function classCondition(array $classes): string
    {
        $parts = [];
        foreach ($classes as $c) {
            $parts[] = "contains(concat(' ', normalize-space(@class), ' '), ' {$c} ')";
        }
        return implode(' and ', $parts);
    }

    /** @return DOMElement[] visi <$tag> elementai su visomis $classes klasėmis, dokumento tvarka */
    public static function findAllByTagAndClasses(DOMXPath $xp, DOMNode $context, string $tag, array $classes): array
    {
        $cond = self::classCondition($classes);
        $query = ".//{$tag}[{$cond}]";
        $result = [];
        foreach ($xp->query($query, $context) as $node) {
            $result[] = $node;
        }
        return $result;
    }

    /** @return DOMElement[] visi elementai (bet kokio žymo) su TIKSLIAI ta klase tarp klasių sąrašo */
    public static function findAllByClass(DOMXPath $xp, DOMNode $context, string $class): array
    {
        $query = ".//*[" . self::classCondition([$class]) . "]";
        $result = [];
        foreach ($xp->query($query, $context) as $node) {
            $result[] = $node;
        }
        return $result;
    }

    /** @return DOMElement[] visi $tag elementai (bet kokio lygio palikuonys), dokumento tvarka */
    public static function findAllTag(DOMXPath $xp, DOMNode $context, string $tag): array
    {
        $result = [];
        foreach ($xp->query(".//{$tag}", $context) as $node) {
            $result[] = $node;
        }
        return $result;
    }

    public static function findTag(DOMXPath $xp, DOMNode $context, string $tag): ?DOMElement
    {
        $nodes = $xp->query(".//{$tag}", $context);
        return $nodes->length ? $nodes->item(0) : null;
    }

    /** tiesioginiai vaikai (recursive=False atitikmuo), filtruojant pagal žymą (arba visi, jei $tag === null) */
    public static function directChildren(DOMNode $node, ?string $tag = null): array
    {
        $result = [];
        foreach ($node->childNodes as $child) {
            if (!($child instanceof DOMElement)) {
                continue;
            }
            if ($tag === null || $child->tagName === $tag) {
                $result[] = $child;
            }
        }
        return $result;
    }

    public static function hasClass(DOMElement $el, string $class): bool
    {
        $attr = $el->getAttribute('class');
        if ($attr === '') {
            return false;
        }
        $classes = preg_split('/\s+/', trim($attr));
        return in_array($class, $classes, true);
    }

    /**
     * "Plokščias" viso dokumento node sąrašas dokumento (preorder) tvarka, įskaitant teksto mazgus,
     * kartu su node -> index žemėlapiu greitai paieškai.
     * Atitinka bs4 .next_element grandinę (kuri irgi eina per VISUS mazgus, ne tik žymas).
     */
    public static function flatten(DOMNode $root): FlatDoc
    {
        $flat = [];
        self::flattenRecursive($root, $flat);
        $index = new SplObjectStorage();
        foreach ($flat as $i => $node) {
            $index[$node] = $i;
        }
        return new FlatDoc($flat, $index);
    }

    private static function flattenRecursive(DOMNode $node, array &$flat): void
    {
        $flat[] = $node;
        if ($node->hasChildNodes()) {
            foreach ($node->childNodes as $child) {
                self::flattenRecursive($child, $flat);
            }
        }
    }

    /** atitinka bs4 `element.next` / `.next_element` */
    public static function next(FlatDoc $flat, ?DOMNode $node): ?DOMNode
    {
        if ($node === null || !$flat->index->contains($node)) {
            return null;
        }
        $idx = $flat->index[$node];
        return $flat->list[$idx + 1] ?? null;
    }

    /** atitinka bs4 `element.find_next(tag)` - pirmas $tag elementas dokumente PO $node */
    public static function findNextTag(FlatDoc $flat, ?DOMNode $node, string $tag): ?DOMElement
    {
        if ($node === null || !$flat->index->contains($node)) {
            return null;
        }
        $idx = $flat->index[$node];
        for ($i = $idx + 1, $n = count($flat->list); $i < $n; $i++) {
            $candidate = $flat->list[$i];
            if ($candidate instanceof DOMElement && $candidate->tagName === $tag) {
                return $candidate;
            }
        }
        return null;
    }
}

final class FlatDoc
{
    /** @param DOMNode[] $list */
    public function __construct(public readonly array $list, public readonly SplObjectStorage $index)
    {
    }
}
