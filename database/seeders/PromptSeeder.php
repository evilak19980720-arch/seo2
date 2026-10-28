<?php

namespace Database\Seeders;

use App\Enums\PromptType;
use App\Models\Prompt;
use Illuminate\Database\Seeder;

/**
 * Default prompt templates (version 1, any site type, any language).
 * Every prompt writes natively in the site's language — never translate.
 * Override per site type / language by adding a more specific prompt.
 */
class PromptSeeder extends Seeder
{
    public const SYSTEM = 'You are a senior content strategist and SEO editor for the official website of {{product_name}} ({{site_type}}). Write natively in {{language}} for readers in {{country}}; never write in another language and never translate from another language. The BRAND PROFILE is the only source of facts: never invent features, numbers, prices, awards, reviews, release dates or company details that are not in it. Always answer with a single JSON object.';

    /** Templates revised after the first release ship as a new version; the highest active version wins. */
    public const VERSIONS = [PromptType::DesignConfig->value => 4, PromptType::PageContent->value => 3];

    public function run(): void
    {
        foreach ($this->templates() as $type => $template) {
            Prompt::firstOrCreate(
                ['type' => $type, 'site_type' => null, 'locale' => null, 'version' => self::VERSIONS[$type] ?? 1],
                ['system' => self::SYSTEM, 'template' => $template, 'is_active' => true],
            );
        }
    }

    public function templates(): array
    {
        return [
            PromptType::SiteMeta->value => <<<'TXT'
BRAND PROFILE:
{{brand}}

Write the homepage SEO title and meta description for the official site "{{site_name}}".
Rules: title max 60 characters and must contain "{{product_name}}"; description max 155 characters, specific, no clickbait, no claims missing from the profile.
Return JSON: {"title": "...", "description": "..."}
TXT,
            PromptType::BrandExtract->value => <<<'TXT'
Extract a structured brand profile for a product website from the material below (text and any attached images such as store screenshots or product photos). Only use facts present in the material. Write all text fields in {{language}}.

MATERIAL:
{{material}}

Return JSON:
{"product_name": "", "tagline": "one sentence", "description": "2-4 paragraphs", "selling_points": ["3-6 items"], "specs": [{"key": "", "value": ""}], "target_audience": "", "use_cases": "", "cta_links": [{"label": "", "url": ""}], "faq": [{"question": "", "answer": ""}], "company_name": "", "company_email": "", "company_address": ""}
Use empty strings / arrays for anything not stated.
TXT,
            PromptType::KeywordExpand->value => <<<'TXT'
BRAND PROFILE:
{{brand}}

Propose {{count}} search keywords people in {{country}} would type in {{language}} that the official site of {{product_name}} can genuinely answer with the facts above. Mix: brand + modifier (e.g. "{{product_name}} como jogar"), questions, and non-brand long tail tightly related to the product. Avoid keywords needing facts not in the profile, avoid near-duplicates, lowercase.
Return JSON: {"keywords": [{"keyword": "", "intent": "informational|navigational|commercial|transactional"}]}
TXT,
            PromptType::DesignConfig->value => <<<'TXT'
You are a senior web designer. Design the visual identity of the official website of {{product_name}} so it looks like a complete, polished, modern site (rich home page, clear navigation, strong visual hierarchy), not a bare template.

BRAND PROFILE:
{{brand}}

Logo dominant colour: {{logo_color}}
Reference site: {{reference_url}}
Reference analysis (read from its HTML/CSS; "none" if there is no reference). "structure" lists its home sections in order, already mapped to our block names:
{{reference}}
Attached screenshots, if any, show the reference. Study them closely: layout (portal/lobby with game cards, banner slider, notice bar, dark or light), header type, card shapes, button style, background treatment, spacing.

When there is a reference, the design MUST follow it closely:
- colors: use the reference primary, background and text colours as given; pick secondary/accent from its palette.
- fonts: the whitelisted fonts closest to the reference fonts.
- nav: "portal" for game/casino-style sites with a coloured menu bar, otherwise the closest variant. footer/radius/density/shadow/style: match the reference look.
- home: the same sections in the same order as the reference ("structure" and the screenshots), using our blocks: banner (slider of big promo images), notice (announcement strip), card_grid (grid of game/product cards), stats, features, steps, gallery, faq, download (app download area), cta, article_list.
- "menu": map each reference menu item, in order, to the matching value of "menu_items". For reference menu items with no match (e.g. events, ranking, promotions, VIP, support) add an entry to "extra_pages" ({"label": "<label in {{language}}>", "slug": "<slug in {{language}}>"}, max 5, only items that make sense for this brand), and put "extra_1"…"extra_N" into "menu" at their position.
- "navigation": reproduce the reference header behaviour (see "navigation" in the analysis and the screenshots): "submenu" groups menu items under a parent like the reference dropdowns ({"<parent menu item>": ["<menu item>", …]}, children are left out of the top level of "menu"), "buttons" = 0-2 download/CTA buttons in the header, "mobile_bar" = true when the reference has a fixed bottom navigation bar on mobile, "sticky" = header stays on top while scrolling, "home_link" = menu starts with a Home link.
Never add login, register or account links or buttons: the site has no user accounts.
Without a reference: still build a rich home page (7-9 blocks) appropriate for the brand.
Never copy text, logos or brand names from the reference.
Feedback from the previous attempt (may be empty): {{feedback}}

You may ONLY use values from this whitelist:
{{whitelist}}

For every page type in "page_types" choose an ordered list of blocks with a variant. The first block of a page is its title area (hero or banner on home, hero/prose/faq/... elsewhere). Text colours must have at least 4.5:1 contrast on the background. At most 2 font families.
"custom_css": up to 8KB of CSS that recreates reference details the config cannot express (header/menu bar look, card frames, glows, gradients, section separators, hover effects, background textures). Use the CSS variables --c-primary --c-on-primary --c-secondary --c-on-secondary --c-accent --c-bg --c-surface --c-text --c-muted --radius --shadow and these classes: .nav .nav__inner .nav__bar .nav__links .nav__cta .btn .btn--ghost .hero .hero__art .banner .banner__slide .notice .section .section__head .card-grid .tile .tile__media .tile__tag .tile__body .feature .feature__icon .step .stats-block .faq details .cta .download .post-card .footer. No @import, no external url(), never hide or shrink content, no fixed heights on text containers. Use "" when not needed.

Return JSON:
{"design": {"colors": {"primary": "#", "secondary": "#", "accent": "#", "background": "#", "surface": "#", "text": "#", "muted": "#"}, "fonts": {"heading": "", "body": ""}, "radius": "", "density": "", "shadow": "", "style": {"background": "", "cards": "", "buttons": "", "headings": ""}, "nav": "", "footer": "", "menu": ["product", "extra_1", "blog"], "navigation": {"submenu": {"product": ["guide", "faq"]}, "buttons": 1, "mobile_bar": false, "sticky": true, "home_link": false}, "extra_pages": [{"label": "", "slug": ""}], "pages": {"home": [{"block": "banner", "variant": "slider"}]}}, "custom_css": ""}
TXT,
            PromptType::CustomCss->value => <<<'TXT'
Write a CSS snippet (max 8KB) for the site of {{product_name}} that achieves: {{request}}
Available CSS variables: --c-primary, --c-on-primary, --c-secondary, --c-accent, --c-bg, --c-surface, --c-text, --c-muted, --radius, --shadow. Main classes: .btn, .hero, .nav, .card, .section, .footer, .feature, .post-card.
Rules: no @import, no external url(), never hide or shrink main content, keep it performant (no heavy filters on large areas).
Return JSON: {"css": "..."}
TXT,
            PromptType::PageContent->value => <<<'TXT'
BRAND PROFILE:
{{brand}}

Write the core pages of the official site of {{product_name}}. Page types with their label hint, default slug and the blocks the design uses:
{{pages}}

For every page type return a URL slug in {{language}} (lowercase, hyphens, keyword-based, max 2 segments, keep the default if fine), a visible title in {{language}} (the labels are only hints, never copy another language), an SEO meta_title (max 60 chars) and meta_description (max 155 chars), and content for each of its blocks. Write like a polished official site: every block needs real copy, never leave text fields empty, and only state facts from the profile.
- hero: eyebrow (2-4 words), heading, subheading (one or two sentences), cta_label, points (3 benefit phrases of max 4 words each)
- banner: items [{title, text}] (1-3 slides: short punchy headline + one sentence), cta_label
- notice: text (one short announcement sentence), label (link text)
- features: eyebrow (1-3 words), heading, intro, items [{title, text}] (3 or 6 items from the selling points; text = one or two concrete sentences each)
- card_grid: eyebrow, heading, intro, items [{title, text, tag}] (4-8 cards about modes, features, content or products of this brand; tag = 1 word like "Novo" or "Top" or empty)
- steps: heading, intro, items [{title, text}] (3-4 steps to start using the product)
- stats: heading (optional), items [{title: short value, text: label}] (2-4 key facts from the profile, e.g. platforms, genre, price "Grátis")
- faq: heading, items [{question, answer}] (answers only from the profile)
- cta / download: heading, text, label
- prose: heading, html (semantic HTML with <h2>/<p>/<ul>, 300-700 words, factual and useful; for extra pages write about that topic for this brand)
- gallery / specs / article_list: heading, intro
- contact: heading, html (short intro)
- legal: html (privacy policy / terms appropriate for {{country}}, e.g. LGPD for Brazil; generic, no invented company data)
Return JSON: {"pages": {"<type>": {"slug": "", "title": "", "meta_title": "", "meta_description": "", "blocks": {"<block>": {...}}}}}
TXT,
            PromptType::ArticleGenerate->value => <<<'TXT'
BRAND PROFILE:
{{brand}}

Write an original {{article_type}} article for the official site of {{product_name}}.
Target keyword: "{{keyword}}" (search intent: {{intent}}). Length: {{min_words}}-{{max_words}} words.
Existing article titles on this site (do not repeat their angle):
{{existing_titles}}

Rules: answer the searcher's question in the first paragraph; use 3-6 <h2> sections (and <h3> when useful), short paragraphs, lists where they help; every product fact must come from the brand profile — if the profile lacks information, write around it instead of inventing; no filler, no "in conclusion" boilerplate, no mention of being AI.
Return JSON: {"title": "", "slug": "keyword-based slug in {{language}}", "meta_title": "max 60 chars", "meta_description": "max 155 chars", "featured_image_alt": "", "html": "<p>…</p><h2>…</h2>…"}
TXT,
            PromptType::ArticlePolish->value => <<<'TXT'
BRAND PROFILE:
{{brand}}

Polish this article for native {{language}} readers: fix grammar, make sentences natural and concise, keep the structure (<h2>/<h3>), keep the target keyword "{{keyword}}" natural, remove repetition and filler. Do not add any facts that are not in the brand profile; remove any that are not supported.
ARTICLE HTML:
{{html}}

Return JSON: {"html": "..."}
TXT,
            PromptType::ArticleReview->value => <<<'TXT'
BRAND PROFILE:
{{brand}}

Review this article (target keyword "{{keyword}}") as a strict editor. Fail it if: it is not in {{language}}; it does not satisfy the search intent; it is thin, repetitive or generic; it contains placeholders or AI disclaimers; it states product facts not in the brand profile; it contains misleading claims.
ARTICLE HTML:
{{html}}

Return JSON: {"pass": true|false, "issues": ["..."]}
TXT,
            PromptType::FactCheck->value => <<<'TXT'
BRAND PROFILE (the only source of truth):
{{brand}}

ARTICLE:
{{article}}

List every claim in the article about the product, its features, numbers, platforms, prices, dates or the company that cannot be supported by the brand profile. General, non-product statements are fine.
Return JSON: {"supported": true|false, "unsupported_claims": ["..."]}
TXT,
            PromptType::BrandTranslate->value => <<<'TXT'
Rewrite this brand profile natively in {{language}} for readers in {{country}} (localize, do not translate word-for-word; keep product name, URLs and emails unchanged; do not add facts).
PROFILE JSON:
{{profile}}

Return the same JSON structure.
TXT,
        ];
    }
}
