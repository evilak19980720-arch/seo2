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
    public const SYSTEM = 'You are a senior content strategist and SEO editor for the official website of {{product_name}} ({{site_type}}). Write natively in {{language}} for readers in {{country}}; never write in another language and never translate from another language. The BRAND PROFILE is the only source of facts: never invent features, numbers, prices, awards, reviews, release dates or company details that are not in it. Write only copy a visitor reads: never mention the brand profile, the sources or what they do or do not include, never write "based on the information provided", placeholders or notes to the editor; when a fact is missing, simply leave it out. Always answer with a single JSON object.';

    /** Templates revised after the first release ship as a new version; the highest active version wins. */
    public const VERSIONS = [PromptType::DesignConfig->value => 6, PromptType::PageContent->value => 9, PromptType::SiteData->value => 4, PromptType::SiteMeta->value => 3, PromptType::ArticleGenerate->value => 3, PromptType::ArticlePolish->value => 3, PromptType::EntryContent->value => 2, PromptType::SiteReview->value => 3];

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
Rules: title 35-60 characters = "{{product_name}}" plus what it is, in the words people in {{country}} search (main keyword, e.g. "{{product_name}}: free space racing game"), never the brand name alone, no season or promotion; description 120-155 characters naming the product, what it does and one concrete benefit from the profile, no clickbait, no numbers, prices, bonuses or superlatives that are not in the profile.
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
Attached screenshots, if any, show the reference. Study them: its palette, dark or light mood, which sections the home page has and in which order, and what its header does.

The header type, footer type, fonts, corners, shadows, card / button / heading style, block variants and phone header / menu look are assigned to each site by the platform so that no two sites look alike; whatever you answer for them is replaced. Your part:
- colors: use the reference primary, background and text colours as given; pick secondary/accent from its palette.
- density: the spacing that suits the reference.
- home: the same sections in the same order as the reference ("structure" and the screenshots), using our blocks: banner (slider of big promo images), notice (announcement strip), card_grid (grid of game/product cards), stats, features, steps, gallery, faq, download (app download area), cta, article_list.
- "menu": map each reference menu item, in order, to the matching value of "menu_items". For reference menu items with no match (e.g. events, ranking, promotions, VIP, support) add an entry to "extra_pages" ({"label": "<label in {{language}}>", "slug": "<slug in {{language}}>"}, max 5, only items that make sense for this brand), and put "extra_1"…"extra_N" into "menu" at their position.
- "navigation": reproduce the reference header behaviour (see "navigation" in the analysis and the screenshots): "submenu" groups menu items under a parent like the reference dropdowns ({"<parent menu item>": ["<menu item>", …]}, children are left out of the top level of "menu"), "buttons" = 0-2 download/CTA buttons in the header, "mobile_bar" = true when the reference has a fixed bottom navigation bar on mobile, "sticky" = header stays on top while scrolling, "home_link" = menu starts with a Home link.
Never add login, register or account links or buttons: the site has no user accounts.
Without a reference: still build a rich home page (7-9 blocks) appropriate for the brand.
Phones first: most visitors arrive on a phone, so judge every choice at 360-430px wide first. Prefer blocks and variants that read well in one column (carousels and compact grids for long lists, short banners), keep the first screen to the title, one line of text and the main button, and set "navigation.mobile_bar" true unless the reference clearly has no mobile navigation.
Never copy text, logos or brand names from the reference.
Feedback from the previous attempt (may be empty): {{feedback}}

You may ONLY use values from this whitelist:
{{whitelist}}

For every page type in "page_types" choose an ordered list of blocks with a variant. The first block of a page is its title area (hero or banner on home, hero/prose/faq/... elsewhere). Text colours must have at least 4.5:1 contrast on the background. At most 2 font families.
"custom_css": up to 8KB of CSS for decorative details in the reference's mood that the config cannot express (glows, gradients, section separators, hover effects, background textures). Never restyle the header, menu, cards or buttons into the reference's shapes: their look belongs to this site. Use the CSS variables --c-primary --c-on-primary --c-secondary --c-on-secondary --c-accent --c-bg --c-surface --c-text --c-muted --radius --shadow and these classes: .nav .nav__inner .nav__bar .nav__links .nav__cta .btn .btn--ghost .hero .hero__art .banner .banner__slide .notice .section .section__head .card-grid .tile .tile__media .tile__tag .tile__body .feature .feature__icon .step .stats-block .faq details .cta .download .post-card .footer. Write it mobile-first: base rules for phones, wider-screen changes inside @media (min-width: …); outside media queries no fixed widths over 360px, no horizontal scrolling, tap targets at least 44px high. No @import, no external url(), never hide or shrink content, no fixed heights on text containers. Use "" when not needed.

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

Write the core pages of the official site of {{product_name}}. Page types with their label hint, default slug and the blocks the design uses (or, for sectioned pages, "sections": section key => {block, topic}):
{{pages}}

SITE FEATURES: {{site_features}}

REFERENCE SITE CONTENT ("none" if not used):
{{reference_content}}
When reference content is given, use it as the model for topics, section ideas, page structure and level of detail, and rewrite everything originally in {{language}}: new wording, never copy a sentence or more than 6 words in a row; replace the reference's brand, product and company names with {{product_name}}. Facts (games, providers, promotion and event amounts, dates, requirements and terms) may come from the brand profile or the reference content, exactly as stated there; never invent others.

SEO rules for every page:
- The home hero heading (the page's h1) names {{product_name}} and what it is; never a season, holiday, event or promotion headline.
- When a page has "min_words", its blocks together must reach that many words of useful, specific copy. When a page has "feedback", fix exactly that.
- No figures, prices, bonuses, percentages, payout/withdrawal times, rankings or superlatives ("best", "#1", "leading", "guaranteed") unless the brand profile or the reference content states them.
- Never describe a feature the site does not have (see SITE FEATURES): without accounts no login, sign-up, register or account wording; without payments nothing about deposits, withdrawals or payment methods; licences and certifications only as stated in the brand profile.
- Each page covers its own topic: never repeat another page's title or copy; titles of different pages must not overlap.
- meta_description: 120-155 chars summarising this page.

For every page type return a URL slug in {{language}} (lowercase, hyphens, keyword-based, max 2 segments, keep the default if fine), a visible title in {{language}} (the labels are only hints, never copy another language; it is the menu label and column name: 1-4 words naming the topic only, never {{product_name}} or any brand or site name, no separators), an SEO meta_title (max 60 chars) and meta_description (max 155 chars), and content for each of its blocks. Write like a polished official site: every block needs real copy, never leave text fields empty, and only state facts from the profile.
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
- entry_list / game_lobby / html_sitemap / event_calendar / site_search / link_grid / cookie_settings: heading, intro (one or two sentences introducing it; the items come from the site data)
- timeline: heading, intro, items [{title: date or stage, text}] (only real dates and events from the sources)
- glossary: heading, intro, items [{title: term, text: definition}] (6-16 terms)
- checklist: heading, intro, items [{title, text}] (3-8 points)
- callout: heading, text (one or two sentences: an important notice)
- quote: heading, text (the statement), cite (who said it; only a real person or the brand from the sources)
- table: heading, intro, items [{title: label, text: value}] (2-12 rows, values only from the sources or general facts)
For sectioned pages, "blocks" is keyed by the section keys (not block names); each section gets the fields of its block and a heading about its topic, prose sections 120-350 words each, and min_words counts all sections together. A section marked [sources only] uses only facts from the brand profile or the reference content: leave it out of "blocks" when they say nothing about it.
Return JSON: {"pages": {"<type>": {"slug": "", "title": "", "meta_title": "", "meta_description": "", "blocks": {"<block or section key>": {...}}}}}
TXT,
            PromptType::ArticleGenerate->value => <<<'TXT'
BRAND PROFILE:
{{brand}}

Write an original {{article_type}} article for the official site of {{product_name}}.
Target keyword: "{{keyword}}" (search intent: {{intent}}). Length: {{min_words}}-{{max_words}} words.
Existing article titles on this site (do not repeat their angle):
{{existing_titles}}

Rules: answer the searcher's question in the first paragraph; use 3-6 <h2> sections (and <h3> when useful), short paragraphs, lists where they help; every product fact must come from the brand profile — if the profile lacks information, write around it instead of inventing; no filler, no "in conclusion" boilerplate, no mention of being AI.
Illustrations: where a picture genuinely helps the reader (a scene, a concept, a step), put an empty placeholder <figure data-illustration="what the picture shows, in English, one sentence"></figure> on its own between paragraphs; 0-3 per article, never two in a row, no pictures of text, screenshots, logos or real people.
Return JSON: {"title": "", "slug": "keyword-based slug in {{language}}", "meta_title": "max 60 chars", "meta_description": "max 155 chars", "featured_image_alt": "", "html": "<p>…</p><h2>…</h2>…"}
TXT,
            PromptType::ArticlePolish->value => <<<'TXT'
BRAND PROFILE:
{{brand}}

Polish this article for native {{language}} readers: fix grammar, make sentences natural and concise, keep the structure (<h2>/<h3>), keep the target keyword "{{keyword}}" natural, remove repetition and filler. Do not add any facts that are not in the brand profile; remove any that are not supported. Keep every <figure data-illustration="…"></figure> placeholder exactly as it is, in the same place.
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
            PromptType::SiteData->value => <<<'TXT'
BRAND PROFILE:
{{brand}}

REFERENCE SITE ({{reference_url}}; "none" if there is no reference). Its pages, then the numbered picture tiles found on them (#id | name | text next to the picture):
{{reference}}

List the site data of the official site of {{product_name}}, written in {{language}}, for these collections: {{collections}}.

Rules:
- Take the items from the sources above: the games, providers, promotions, events, announcements and news that the reference site or the brand profile shows. Keep the real names of games and providers.
- Keep amounts, percentages, dates, requirements and terms exactly as the sources state them. Never invent an item, a figure, a date, a licence, a winner or a result; leave a field "" (or []) when the sources do not give it.
- Replace the reference's own brand and company names with {{product_name}}.
- summary: one or two sentences in your own words (never copy more than 6 words in a row).
- TILES are the pictured items of the reference (game covers, provider logos, promotion and event banners). Build the game, provider, promotion, event and news lists from them first: one item per tile that shows such an item, with "tile" = its #id number and "title" = the tile name copied exactly (only the reference's own brand name replaced by {{product_name}}). Only then add items the text names that have no tile, with "tile": 0. Never give one tile to two items, never give a tile to an item it does not show.
- slug: lowercase ASCII words with hyphens, unique per collection.
- Dates as YYYY-MM-DD. A collection the sources say nothing about gets [].
- Site features: {{site_features}}. Nothing about accounts or payments the site does not have.

Fields per collection:
- game: title, slug, summary, tile, provider, series, themes [] (free words), mechanics [] (free words), types [] (any keys of GAME TYPES), theme_keys [] (any keys of GAME THEMES), devices [] (any of "mobile", "desktop", "tablet"), demo (true when the sources say a free demo exists), tags (any of "hot", "new", "classic", "featured", "upcoming"), rtp, volatility, max_win, paylines, reels, min_bet, max_bet, release_date
- provider: title, slug, summary, tile, country, founded
- promotion: title, slug, summary, tile, tags (any of "new_user", "regular", "festival", "featured", "anniversary", "app", "invite"), reward, requirement, starts_at, ends_at, status ("upcoming"|"ongoing"|"ended"|"")
- event: title, slug, summary, tile, tags (any of "featured", "launch", "anniversary", "festival", "community", "quiz", "creative", "collab", "live", "offline"), reward, requirement, starts_at, ends_at, status
- notice: title, slug, summary, date, tags (any of "maintenance", "game_maintenance", "rules", "restored", "new_game", "removed", "game_update", "payments", "policy", "channels")
- news: title, slug, summary, tile, date, tags (any of "brand", "new_games", "features", "updates", "partners", "editorial", "monthly", "community")
GAME TYPES (key = meaning): {{game_types}}
GAME THEMES (key = meaning): {{game_themes}}
At most {{max_items}} items per collection.
Return JSON: {"<collection>": [{...}]}
TXT,
            PromptType::EntryContent->value => <<<'TXT'
BRAND PROFILE:
{{brand}}

REFERENCE CONTENT about these items ("none" if there is none):
{{reference}}

Write the detail pages of these items of the official site of {{product_name}} in {{language}}. Each item lists its known data and the sections to write (key => topic):
{{items}}

Rules: every section is {"heading": "<h2 text about the topic, in {{language}}>", "html": "<p>…</p> (80-220 words, semantic HTML: <p>, <ul>, <h3>)"}; useful and specific to that item; facts only from the item data, the reference content or the brand profile (amounts, dates and terms exactly as stated); never invent RTP, payouts, winners or results; skip a section (leave it out) when there is nothing true to say; when an item has "feedback", fix exactly that; your own wording, never more than 6 words copied in a row; site features: {{site_features}}.
Return JSON: {"items": {"<slug>": {"<section key>": {"heading": "", "html": ""}}}}
TXT,
            PromptType::UiTranslate->value => <<<'TXT'
Translate these website interface strings from English into {{language}}, as a native speaker in {{country}} would write them on a polished website. Short labels stay short (menus, buttons); keep placeholders such as :site, :brand, :n and :name exactly as they are; keep HTML tags unchanged; translate everything else, never leave English words except product names.
STRINGS (key => English):
{{strings}}

Return JSON with the same keys: {"<key>": "<translation>"}
TXT,
            PromptType::BrandComplete->value => <<<'TXT'
The official website of {{product_name}} ({{site_type}}) is modelled on the reference site below: the same kind of product, possibly under another brand name ({{reference_name}}). Write the brand profile of {{product_name}} in {{language}} from what the reference site says about its product: what it is, what you can do with it, its main features and categories, who it is for.
- Use only facts stated in the reference text; never invent figures, licences, awards, rankings or partnerships.
- The reference site's own brand names become {{product_name}}; keep names of games, providers and payment methods.
- Leave out logins, sign-up, deposits and contact details.
- Keep it consistent with what the current profile already says.

CURRENT PROFILE:
{{brand}}

REFERENCE SITE:
{{reference}}

Return JSON:
{"tagline": "one sentence, at most 120 characters, naming {{product_name}} and what it is", "description": "3-5 paragraphs, 250-600 words, plain text", "selling_points": ["3-6 short items"], "specs": [{"key": "", "value": ""}], "target_audience": "", "use_cases": "", "faq": [{"question": "", "answer": ""}]}
Use empty strings / arrays for anything the reference does not support.
TXT,
            PromptType::SiteReview->value => <<<'TXT'
You are a senior web designer and technical SEO reviewer. Review the generated official website of {{product_name}} ({{site_type}}, written in {{language}} for {{country}}).
{{screenshots}}

BRAND PROFILE:
{{brand}}

PROGRAM CHECKS (measured on the built pages; "browser" lists low-contrast text, horizontal overflow, broken images per page and device):
{{checks}}

CURRENT DESIGN (option values; header, footer, phone menu, fonts, corners, card style and hero are this site's identity, taken from its reference, and are NOT yours to change):
{{design}}

ALLOWED OPTIONS (you may only pick from these; nothing else can be changed):
{{options}}

PAGES (h1, meta title, meta description):
{{pages}}

AI CUSTOM CSS currently applied:
{{custom_css}}

Score the site from 0 to 100:
- "design": does it look like a polished, premium official brand site (clear hierarchy, generous spacing, consistent colours, strong hero, nothing cluttered, overlapping, unreadable or broken), first on the phone screenshot, and how close is it to the reference look when reference screenshots are given?
- "seo": titles and descriptions (clear main keyword in {{language}}, 35-60 / 120-155 characters, unique per page, no season or unsupported claims), one H1, readable text, the program checks above.
Then list the concrete issues you see (each one short sentence in Simplified Chinese, at most 8 issues, for the Chinese-speaking admin), and a SMALL change list that fixes only those issues using ONLY the allowed options. Your job is to repair defects, not to restyle: every site must keep its own look, so never move the design towards a generic or "safe" default. Change at most 2 colours and only when text is unreadable (keep the brand hue, adjust lightness); change at most 2 block variants and only when that block is visibly broken, cluttered or far from the reference; never invent facts. Rewrite meta_title / meta_description only for pages whose current text is weak, duplicated or the wrong length; write them natively in {{language}}. Set "drop_custom_css" to true only when the custom CSS visibly breaks the layout or colours.
Leave anything that is already good out of the change list.

Return JSON:
{"scores": {"design": 0, "seo": 0}, "issues": ["short issue"], "design": {"colors": {"text": "#rrggbb"}, "density": "", "shadow": "", "pages": {"home": [{"block": "faq", "variant": ""}]}}, "pages": {"home": {"meta_title": "", "meta_description": ""}}, "drop_custom_css": false}
TXT,
        ];
    }
}
