<?php

/*
| Game site preset: a pool of pages (big sections) and of sections inside
| each page (small sections). Every site keeps the required pages and a
| seeded random selection of the others; inside each page its required
| sections plus a random selection of the rest (at most "max_sections").
| Detail pages of games, providers, promotions and events combine their
| sections the same way.
|
| Section fields: key, block, label (admin), topic (for the AI), and
|   collection/filter  list of site data (filters: all, hot, new, classic, featured,
|                      upcoming, active, ongoing, ended, latest, tag:x, type:x,
|                      theme:x, device:x)
|   links              link_grid targets: page types, or "sections:<page>"
|   tabs               a list switches between the tags its items share (tags are filters, not sections)
|   match / core       knowledge articles: written when a site game's mechanics contain a match word, or always (core)
|   requires           app | company | license | certification
|   source             true = only facts from the brand profile or the reference;
|                      the section is left out when the sources say nothing
| "nav": main = header, secondary = dropdown under "parent", footer, legal.
| "split" pages (types, themes, knowledge) keep their "index" sections on the page and turn every
|   other chosen section into a level-3 page of its own; SiteStructure::fit keeps only those the site
|   data fills (lists with "min_items" items, articles about mechanics the games use), at most "max".
| Features that need a live backend (my offers, transaction records, ticket
| status, event sign-up, newsletter) are not part of the pool: a static site
| cannot provide them.
*/

$s = fn (string $key, string $block, string $label, string $topic, array $extra = []): array => compact('key', 'block', 'label', 'topic') + $extra;
$req = ['required' => true];
$src = ['source' => true];
$list = fn (string $key, string $label, string $topic, string $collection, string $filter, array $extra = []) => $s($key, 'entry_list', $label, $topic, ['collection' => $collection, 'filter' => $filter] + $extra);
$slug = fn (string $en, string $pt, string $es): array => compact('en', 'pt', 'es');

// Vocabulary the site data uses for game categories (keys are stored on games).
$types = [
    'classic_3_reel' => ['经典三轴 Slots', 'classic three-reel slots'],
    'five_reel' => ['五轴 Slots', 'five-reel slots'],
    'video' => ['视频 Slots', 'video slots'],
    'multi_line' => ['多线 Slots', 'multi-line slots'],
    'ways' => ['多路赔付 Slots', 'ways-to-win slots'],
    'cluster' => ['集群赔付 Slots', 'cluster pays slots'],
    'megaways' => ['Megaways 类游戏', 'Megaways games'],
    'cascading' => ['连锁消除类游戏', 'cascading / tumbling reels games'],
    'hold_and_win' => ['Hold and Win 类游戏', 'hold and win games'],
    'respin' => ['重转机制游戏', 'games with respins'],
    'expanding_reels' => ['扩展卷轴游戏', 'expanding reels games'],
    'variable_reels' => ['可变卷轴游戏', 'variable reels games'],
    'multi_level' => ['多层卷轴游戏', 'multi-level reels games'],
    'fixed_jackpot' => ['固定奖池游戏', 'fixed jackpot games'],
    'progressive_jackpot' => ['累积奖池游戏', 'progressive jackpot games'],
    'series' => ['系列游戏合集', 'game series'],
    'branded' => ['品牌联名游戏', 'branded / licensed games'],
];
$themes = [
    'egypt' => ['古埃及主题', 'ancient Egypt'], 'greek' => ['希腊神话主题', 'Greek mythology'], 'norse' => ['北欧神话主题', 'Norse mythology'],
    'asian' => ['亚洲文化主题', 'Asian culture'], 'history' => ['历史文明主题', 'historic civilisations'], 'fantasy' => ['奇幻冒险主题', 'fantasy adventure'],
    'magic' => ['魔法主题', 'magic'], 'pirates' => ['海盗主题', 'pirates'], 'ocean' => ['海洋主题', 'ocean'], 'animals' => ['动物主题', 'animals'],
    'fruit' => ['水果主题', 'fruit'], 'candy' => ['糖果甜品主题', 'candy and sweets'], 'gems' => ['宝石主题', 'gems'], 'gold' => ['黄金宝藏主题', 'gold and treasure'],
    'western' => ['西部牛仔主题', 'wild west'], 'space' => ['太空科幻主题', 'space and sci-fi'], 'music' => ['音乐主题', 'music'], 'sports' => ['体育主题', 'sports'],
    'movies' => ['电影与角色授权主题', 'movies and licensed characters'], 'retro' => ['复古街机主题', 'retro arcade'], 'holiday' => ['节日主题', 'holidays'],
    'seasonal' => ['季节限定主题', 'seasonal'],
];

return [
    'label' => '游戏品牌站',
    'schema' => 'VideoGame',
    'sectioned' => true,
    'responsible_notice' => true,
    'main_nav_max' => 8,
    // A site first gets one positioning (drawn at random, a regeneration draws another): it decides the
    // header menu (6–8 entries with home and News), each menu item's dropdown, and the home page's
    // sections in order. Footer pages are fixed, plus a few drawn from "footer_optional".
    // "blog" is the News centre (generated articles); it is always in the menu.
    // Levels: menu page (level 1) → dropdown page (level 2) → collection page or article split off a
    // "split" page (level 3) → one detail page per item. Item parameters (RTP, volatility, devices,
    // dates…) only appear on the item's own detail page, never as a page or a section of a list.
    'positionings' => [
        'catalog' => [
            'label' => '游戏目录型',
            'menu' => ['games', 'providers', 'academy', 'blog', 'help', 'about'],
            'children' => ['games' => ['slot_types', 'themes'], 'academy' => ['guide'], 'help' => ['notices', 'contact'], 'about' => ['brand_story', 'company', 'official_channels']],
            'home' => ['intro', 'categories', 'featured_games', 'new_games', 'hot_games', 'learn', 'faq', 'support'],
        ],
        'brand' => [
            'label' => '品牌展示型',
            'menu' => ['games', 'providers', 'blog', 'help', 'about', 'business'],
            'children' => ['games' => ['themes', 'slot_types', 'academy'], 'help' => ['guide', 'notices', 'contact'], 'about' => ['brand_story', 'company', 'press', 'official_channels']],
            'home' => ['intro', 'about_brand', 'hot_games', 'highlights', 'providers', 'articles', 'faq'],
        ],
        'knowledge' => [
            'label' => '知识内容型',
            'menu' => ['games', 'academy', 'guide', 'blog', 'help', 'about'],
            'children' => ['games' => ['slot_types', 'themes', 'providers'], 'help' => ['notices', 'contact'], 'about' => ['brand_story', 'official_channels']],
            'home' => ['intro', 'start', 'categories', 'featured_games', 'learn', 'articles', 'support'],
        ],
        'promo' => [
            'label' => '优惠活动型',
            'menu' => ['games', 'promotions', 'events', 'blog', 'help', 'about'],
            'children' => ['games' => ['providers', 'slot_types', 'themes', 'academy'], 'help' => ['guide', 'notices', 'contact'], 'about' => ['brand_story', 'company', 'official_channels']],
            'home' => ['intro', 'live_events', 'promos', 'hot_games', 'trust', 'latest_notices', 'faq', 'support'],
        ],
    ],
    // Pages every positioning has (footer and legal), and a few more drawn per site.
    'footer_pages' => ['responsible', 'terms', 'privacy', 'cookies', 'regions', 'sitemap', 'search'],
    'footer_optional' => ['security', 'fairness', 'community', 'game_terms', 'ip', 'accessibility'],
    'footer_optional_count' => [2, 3],
    // Pages outside the main menu worth a footer link, most useful first (the footer keeps only a few).
    'footer_picks' => ['responsible', 'help', 'contact', 'about', 'download', 'security', 'fairness', 'community'],
    // The app page joins the menu (before help) when the brand has an app.
    'app_page' => 'download',
    // Pages that no longer exist on their own, and the page their old address now leads to (301).
    'moved' => ['game_math' => 'academy', 'lobby' => 'games', 'news' => 'blog', 'status' => 'notices', 'behind_scenes' => 'blog'],
    // Which pages a page or detail page links to first (1.md section 7); missing ones are skipped.
    'related' => [
        'games' => ['providers', 'slot_types', 'themes', 'academy'],
        'providers' => ['games', 'slot_types', 'academy'],
        'slot_types' => ['themes', 'academy', 'games'],
        'themes' => ['slot_types', 'games', 'academy'],
        'academy' => ['guide', 'slot_types', 'games'],
        'guide' => ['academy', 'help', 'games'],
        'promotions' => ['bonus_terms', 'events', 'help'],
        'events' => ['promotions', 'games', 'help'],
        'notices' => ['help', 'contact', 'security'],
        'help' => ['contact', 'guide', 'notices'],
        'about' => ['brand_story', 'official_channels', 'contact'],
        'entry:game' => ['academy', 'slot_types', 'themes'],
        'entry:provider' => ['games', 'slot_types'],
        'entry:promotion' => ['bonus_terms', 'help', 'games'],
        'entry:event' => ['promotions', 'games', 'help'],
        'entry:notice' => ['help', 'contact', 'games'],
    ],
    // Sections per content page (home has its own list).
    'page_sections' => [3, 6],
    // Phones first: a fixed bottom bar (home, games, download…) even when the reference has none.
    'mobile_bar' => true,
    'main_nav_order' => ['games', 'promotions', 'events', 'providers', 'academy', 'download', 'about', 'help'],
    'optional_page_chance' => 0.6,
    'optional_section_chance' => 0.55,
    'max_sections' => 9,
    'game_types' => array_map(fn ($t) => $t[0], $types),
    'game_themes' => array_map(fn ($t) => $t[0], $themes),

    // Site data shown in lists and on detail pages (/{owner page}/{entry}/); owner = first chosen page.
    'collections' => [
        // Detail pages follow one fixed order per kind (1.md templates); every section is written, from
        // the item data, the reference and what is publicly known about the item, never with invented figures.
        'game' => ['label' => '游戏', 'owners' => ['games'], 'sections' => [
            $s('details', 'prose', '游戏简介', 'what the game is: theme, look, setting', $req),
            $s('features', 'prose', '游戏特色', 'what makes the game stand out', $req),
            $s('rules', 'prose', '玩法与卷轴结构', 'how to play: reels, rows and how wins are paid', $req),
            $s('symbols', 'prose', '符号与角色', 'the regular and special symbols, and the characters of the game, and what they do', $req),
            $s('mechanics', 'prose', '特色机制与触发条件', 'special mechanics and bonus features, and how they start', $req),
            $s('paytable', 'prose', '规则与赔付表说明', 'how to read the rules and paytable screen', $req),
            $s('devices', 'prose', '支持设备', 'devices and browsers the game runs on', $req),
            $s('faq', 'prose', '游戏常见问题', 'common questions about this game', $req),
        ], 'blocks' => ['params' => '游戏参数', 'media' => '游戏截图', 'series' => '同系列 / 相似游戏推荐']],
        'provider' => ['label' => '供应商', 'owners' => ['providers'], 'sections' => [
            $s('details', 'prose', '供应商简介', 'who the provider is and its style', $req),
            $s('mechanics', 'prose', '供应商特色机制', 'mechanics the provider is known for', $req),
            $s('story', 'prose', '供应商背景与研发工作室', 'the provider\'s background and studios', $src),
        ], 'blocks' => ['games' => '供应商游戏合集', 'latest' => '供应商新作']],
        'promotion' => ['label' => '优惠', 'owners' => ['promotions'], 'sections' => [
            $s('details', 'prose', '优惠内容摘要', 'what the offer is', $req),
            $s('eligibility', 'prose', '参与资格', 'who can take part', $req),
            $s('games', 'prose', '适用范围', 'games and products the offer applies to', $req),
            $s('limits', 'prose', '限制条件', 'limits and restrictions', $req),
            $s('terms', 'prose', '完整规则', 'full rules of this offer', $req),
        ], 'blocks' => []],
        'event' => ['label' => '活动', 'owners' => ['events'], 'sections' => [
            $s('details', 'prose', '活动主题', 'what the event is', $req),
            $s('progress', 'prose', '活动流程', 'how the event runs, step by step', $req),
            $s('rules', 'prose', '活动规则', 'rules of this event', $req),
            $s('results', 'prose', '活动结果', 'results of the event', $src),
        ], 'blocks' => []],
        'notice' => ['label' => '公告', 'owners' => ['notices'], 'sections' => [
            $s('details', 'prose', '公告内容', 'the announcement', $req),
            $s('scope', 'prose', '影响范围与当前状态', 'what it affects, since when, and its current state', $req),
        ], 'blocks' => []],
    ],

    'pages' => [
        // 一、首页与品牌展示
        'home' => [
            'label' => '品牌首页', 'slug' => ['en' => ''], 'nav' => 'none', 'required' => true, 'min_words' => 300, 'max_sections' => 11,
            'sections' => [
                $s('intro', 'hero', '品牌介绍', 'brand introduction: who we are and what players find here', $req),
                $s('highlights', 'features', '品牌特色', 'what makes the brand different'),
                $list('hot_games', '热门游戏推荐', 'popular games', 'game', 'hot'),
                $list('new_games', '新游戏推荐', 'newly added games', 'game', 'new'),
                $list('featured_games', '编辑精选', 'editor\'s picks', 'game', 'featured'),
                $s('categories', 'link_grid', '游戏分类入口', 'shortcuts to the game categories', ['links' => 'sections:slot_types']),
                $list('promos', '优惠精选', 'featured promotions', 'promotion', 'featured'),
                $list('live_events', '正在进行的活动', 'events running now', 'event', 'ongoing'),
                $list('latest_notices', '最新公告', 'latest announcements', 'notice', 'latest'),
                $s('articles', 'article_list', '最新文章', 'latest articles from the news centre'),
                $s('about_brand', 'prose', '品牌简介', 'who the brand is and what it offers players'),
                $s('start', 'steps', '入门指南', 'how a new visitor gets started, in a few steps'),
                $s('learn', 'link_grid', '游戏知识入口', 'guides and knowledge pages', ['links' => ['academy', 'guide', 'slot_types', 'themes']]),
                $s('faq', 'faq', '常见问题', 'a few key questions with short answers'),
                $list('providers', '合作供应商', 'game providers on the site', 'provider', 'all'),
                $s('trust', 'checklist', '安全与信任', 'why players can trust the site (only true facts)'),
                $s('support', 'cta', '客服入口', 'how to reach customer support', ['link' => 'help']),
            ],
        ],
        'about' => [
            'label' => '品牌介绍', 'slug' => $slug('about-us', 'sobre-nos', 'sobre-nosotros'), 'nav' => 'main', 'required' => true,
            'sections' => [
                $s('intro', 'prose', '品牌介绍', 'who the brand is', $req),
                $s('philosophy', 'quote', '品牌理念', 'the brand\'s philosophy in one statement'),
                $s('features', 'features', '品牌特色', 'what makes the brand special'),
                $s('visual', 'prose', '品牌视觉与吉祥物', 'visual identity and mascot', $src),
                $s('global', 'prose', '全球业务', 'where the brand operates', $src),
                $s('regions', 'table', '服务地区', 'regions served (region / note)', $src),
                $s('languages', 'table', '支持语言', 'supported languages', $src),
                $s('more', 'link_grid', '了解更多', 'more about the brand', ['links' => ['brand_story', 'company', 'press', 'official_channels', 'contact', 'business']]),
            ],
        ],
        'brand_story' => [
            'label' => '品牌故事', 'slug' => $slug('our-story', 'nossa-historia', 'nuestra-historia'), 'nav' => 'secondary', 'parent' => 'about',
            'sections' => [
                $s('story', 'prose', '品牌故事', 'the brand story', $req),
                $s('founder', 'quote', '创始人寄语', 'a message from the founder', $src),
                $s('history', 'timeline', '品牌发展历程', 'how the brand developed, in order', $src),
                $s('milestones', 'timeline', '品牌里程碑', 'key milestones with dates', $src),
                $s('honors', 'card_grid', '品牌荣誉', 'awards and honours', $src),
            ],
        ],
        'company' => [
            'label' => '公司介绍', 'slug' => $slug('company', 'empresa', 'empresa'), 'nav' => 'secondary', 'parent' => 'about', 'requires' => 'company',
            'sections' => [
                $s('company', 'prose', '公司介绍', 'the company', $req),
                $s('operator', 'table', '运营主体', 'operating entity details (name, address, registration)', $src),
                $s('team', 'card_grid', '团队介绍', 'the team', $src),
                $s('global', 'prose', '全球业务', 'the company\'s markets', $src),
            ],
        ],
        'press' => [
            'label' => '媒体与合作伙伴', 'slug' => $slug('press', 'imprensa', 'prensa'), 'nav' => 'secondary', 'parent' => 'about',
            'sections' => [
                $s('coverage', 'card_grid', '媒体报道', 'press coverage', $src + $req),
                $s('partners', 'card_grid', '合作伙伴', 'partners', $src),
                $s('media_kit', 'prose', '媒体资料包', 'what is in the media kit and how to request it'),
                $s('brand_guidelines', 'checklist', '品牌标识使用规范', 'rules for using the logo and brand name'),
                $s('press_contact', 'contact', '新闻联系', 'press contact'),
            ],
        ],
        'official_channels' => [
            'label' => '官方渠道', 'slug' => $slug('official-channels', 'canais-oficiais', 'canales-oficiales'), 'nav' => 'secondary', 'parent' => 'about',
            'sections' => [
                $s('channels', 'prose', '官方渠道', 'the official channels', $req),
                $s('domain_check', 'callout', '官方域名验证', 'how to check you are on the official domain'),
                $s('socials', 'prose', '官方社交账号验证', 'how to recognise the official social accounts'),
                $s('impostors', 'checklist', '防冒充说明', 'signs of impostor sites and fake support'),
            ],
        ],
        'contact' => [
            'label' => '联系我们', 'slug' => $slug('contact', 'contato', 'contacto'), 'nav' => 'secondary', 'parent' => 'about',
            'sections' => [
                $s('contact', 'contact', '联系我们', 'how to contact us', $req),
                $s('hours', 'table', '客服服务时间', 'support hours', $src),
                $s('email', 'prose', '邮件支持', 'email support and what to include'),
            ],
        ],

        // 二、游戏（一级）：全部游戏就是带搜索和筛选的大厅；类型、主题、供应商、知识库是它的二级栏目。
        'games' => [
            'label' => '游戏', 'slug' => $slug('games', 'jogos', 'juegos'), 'nav' => 'main', 'required' => true, 'min_words' => 250,
            'sections' => [
                $s('overview', 'prose', '全部游戏介绍', 'overview of the games on the site and how they differ', $req),
                $s('lobby', 'game_lobby', '全部游戏 / 搜索 / 筛选', 'every game with search and filters by provider, type, theme and device', $req),
                $list('hot', '热门游戏', 'popular games', 'game', 'hot'),
                $list('new', '新上线游戏', 'newly added games', 'game', 'new'),
                $s('categories', 'link_grid', '按类型浏览', 'browse by type', ['links' => 'sections:slot_types']),
                $s('faq', 'faq', '游戏常见问题', 'common questions about the games'),
            ],
        ],
        // 二级：游戏类型。每个类型是一个三级合集页（站上至少 3 款该类型的游戏才生成）。
        'slot_types' => [
            'label' => '游戏类型', 'slug' => $slug('slot-types', 'tipos-de-slots', 'tipos-de-slots'), 'nav' => 'secondary', 'parent' => 'games',
            'split' => ['index' => ['intro'], 'min_items' => 3, 'max' => 12],
            'sections' => array_merge(
                [$s('intro', 'prose', '类型说明', 'how slot types differ', $req)],
                array_map(fn ($key, $t) => $list($key, $t[0], $t[1].': what defines them and how they play', 'game', "type:{$key}"), array_keys($types), $types),
            ),
        ],
        // 二级：游戏主题，同上。
        'themes' => [
            'label' => '游戏主题', 'slug' => $slug('game-themes', 'temas-de-jogos', 'temas-de-juegos'), 'nav' => 'secondary', 'parent' => 'games',
            'split' => ['index' => ['intro'], 'min_items' => 3, 'max' => 12],
            'sections' => array_merge(
                [$s('intro', 'prose', '主题说明', 'why themes matter when choosing a game', $req)],
                array_map(fn ($key, $t) => $list($key, $t[0], 'games with a '.$t[1].' theme: what the theme brings', 'game', "theme:{$key}"), array_keys($themes), $themes),
            ),
        ],

        // 二级：知识库。术语词典留在索引页，每个机制是一篇三级文章，只写站上游戏真的用到的机制（每站最多 8 篇）。
        // RTP、波动性等只在术语词典里解释，具体数值只在游戏详情页的参数表。
        'academy' => [
            'label' => '知识库', 'slug' => $slug('slots-guide', 'guia-de-slots', 'guia-de-slots'), 'nav' => 'secondary', 'parent' => 'games',
            'split' => ['index' => ['basics', 'glossary'], 'max' => 8],
            'sections' => [
                $s('basics', 'prose', 'Slots 入门', 'slots basics: what a slot game is made of', $req),
                $s('glossary', 'glossary', '术语词典', 'glossary of slot terms, including RTP, volatility, hit frequency, max win, paylines and ways', $req),
                $s('reels', 'prose', '卷轴与行列', 'reels and rows: how the grid works', ['core' => true]),
                $s('paylines', 'prose', '赔付线', 'paylines: how line wins are paid', ['match' => ['payline', 'linha', 'línea', 'linea']]),
                $s('ways', 'prose', 'Ways 赔付', 'ways to win: how adjacent-reel wins are paid', ['match' => ['ways', 'megaways']]),
                $s('cluster', 'prose', '集群赔付', 'cluster pays: how group wins are paid', ['match' => ['cluster']]),
                $s('wild', 'prose', 'Wild 符号', 'wild symbols: what they replace and their variants', ['core' => true, 'match' => ['wild']]),
                $s('scatter', 'prose', 'Scatter 符号', 'scatter symbols: how they pay and what they start', ['match' => ['scatter']]),
                $s('free_spins', 'prose', '免费旋转', 'free spins: how they start and how they play', ['match' => ['free spin', 'free game', 'giros', 'rodadas', 'tiradas']]),
                $s('respins', 'prose', '重转', 'respins: how they start and what stays in place', ['match' => ['respin', 're-spin', 'hold']]),
                $s('multipliers', 'prose', '倍数', 'multipliers: where they appear and how they grow', ['match' => ['multiplier', 'multiplicador']]),
                $s('cascades', 'prose', '连锁消除', 'cascading reels: how winning symbols are replaced', ['match' => ['cascad', 'tumbl', 'avalanche', 'cascata']]),
                $s('expanding', 'prose', '扩展符号与扩展卷轴', 'expanding symbols and reels', ['match' => ['expand', 'expans']]),
                $s('bonus_rounds', 'prose', '特殊奖励回合', 'bonus rounds: how they start and what happens', ['match' => ['bonus', 'bônus', 'bono']]),
                $s('jackpots', 'prose', '奖池机制', 'how fixed and progressive jackpots work', ['match' => ['jackpot', 'pote', 'bote']]),
                $s('reading_rules', 'steps', '如何阅读游戏规则与赔付表', 'how to read a game\'s rules and paytable screen', ['core' => true]),
            ],
        ],

        // 七、游戏供应商（一级或二级）：列表 + 每个供应商一个详情页（旗下游戏、特色机制、背景都在详情页）。
        'providers' => [
            'label' => '游戏供应商', 'slug' => $slug('providers', 'provedores', 'proveedores'), 'nav' => 'main', 'min_words' => 200,
            'sections' => [
                $s('intro', 'prose', '供应商介绍', 'what a game provider is and how to compare them', $req),
                $list('list', '供应商列表', 'all game providers', 'provider', 'all', $req),
            ],
        ],

        // 八、优惠（一级）：列表按标签切换，资格、限制、规则只写在单项优惠详情；通用条款在页脚条款页。
        'promotions' => [
            'label' => '优惠内容', 'slug' => $slug('promotions', 'promocoes', 'promociones'), 'nav' => 'main', 'required' => true, 'min_words' => 250,
            'sections' => [
                $s('intro', 'prose', '优惠说明', 'what kinds of promotions the site runs and how to read an offer', $req),
                $list('list', '当前优惠', 'promotions running now', 'promotion', 'active', $req + ['tabs' => true]),
                $s('faq', 'faq', '优惠常见问题', 'questions about promotions in general'),
                $list('ended', '已结束优惠', 'promotions that have ended', 'promotion', 'ended'),
            ],
        ],

        // 九、活动（一级）：进行中、即将开始、日历、往期；流程、规则、结果在单项活动详情。
        'events' => [
            'label' => '活动中心', 'slug' => $slug('events', 'eventos', 'eventos'), 'nav' => 'main', 'min_words' => 200,
            'sections' => [
                $s('intro', 'prose', '活动说明', 'what kinds of events the site runs', $req),
                $list('ongoing', '正在进行', 'events running now', 'event', 'ongoing', $req + ['tabs' => true]),
                $list('upcoming', '即将开始', 'events starting soon', 'event', 'upcoming'),
                $s('calendar', 'event_calendar', '活动日历', 'event calendar'),
                $list('past', '往期活动', 'past events', 'event', 'ended'),
            ],
        ],

        // 十、新手中心
        'guide' => [
            'label' => '新手中心', 'slug' => $slug('beginners-guide', 'guia-para-iniciantes', 'guia-para-principiantes'), 'nav' => 'secondary', 'parent' => 'help', 'max_sections' => 10,
            'sections' => [
                $s('start', 'steps', '新用户入门', 'getting started on the site', $req),
                $s('first_use', 'prose', '首次使用指南', 'your first visit'),
                $s('search_guide', 'steps', '如何找到游戏', 'finding a game with search and the filters of the games page'),
                $s('mobile_guide', 'prose', '手机端使用指南', 'using the site on a phone'),
                $s('desktop_guide', 'prose', '电脑端使用指南', 'using the site on a computer'),
                $s('support_guide', 'prose', '客服使用指南', 'getting help from support'),
            ],
        ],

        // 十一、帮助与客服
        'help' => [
            'label' => '帮助中心', 'slug' => $slug('help', 'ajuda', 'ayuda'), 'nav' => 'main', 'required' => true, 'max_sections' => 10,
            'sections' => [
                $s('faq', 'faq', '常见问题', 'frequently asked questions', $req),
                $s('loading', 'faq', '游戏加载问题', 'games not loading'),
                $s('lag', 'faq', '游戏卡顿问题', 'games running slowly'),
                $s('disconnect', 'faq', '游戏断线问题', 'disconnections'),
                $s('app_faq', 'faq', 'App 使用问题', 'app questions', ['requires' => 'app']),
                $s('browser', 'table', '浏览器兼容问题', 'supported browsers (browser / note)'),
                $s('feedback', 'contact', '意见反馈与在线客服', 'how to send feedback or reach support'),
                $s('game_error', 'steps', '游戏错误反馈', 'how to report a game error'),
                $s('complaints', 'steps', '投诉处理流程', 'how complaints are handled'),
                $s('disputes', 'prose', '争议处理说明', 'how disputes are resolved'),
                $s('more', 'link_grid', '更多帮助', 'other help pages', ['links' => ['guide', 'notices', 'contact', 'promotions', 'events', 'responsible']]),
            ],
        ],

        // 十二、公告中心（帮助下的二级）：一个列表按标签切换，每条公告一个详情页。维护、上新等不再是单独的页面或分区。
        'notices' => [
            'label' => '公告中心', 'slug' => $slug('notices', 'avisos', 'avisos'), 'nav' => 'secondary', 'parent' => 'help', 'min_words' => 0,
            'sections' => [
                $list('all', '平台公告', 'all announcements', 'notice', 'all', $req + ['tabs' => true]),
                $s('security', 'callout', '安全提醒', 'a short security reminder, linking to the official channels'),
            ],
        ],

        // 十五、App 与下载
        'download' => [
            'label' => 'App 与下载', 'slug' => $slug('download', 'baixar', 'descargar'), 'nav' => 'main', 'requires' => 'app',
            'sections' => [
                $s('intro', 'download', 'App 介绍 / 官方下载', 'app introduction and official download links', $req),
                $s('features', 'features', 'App 功能介绍', 'what the app does'),
                $s('ios', 'steps', 'iOS 使用说明', 'using it on iPhone'),
                $s('android', 'steps', 'Android 使用说明', 'using it on Android'),
                $s('mobile_web', 'prose', '移动网页版', 'the mobile web version'),
                $s('install', 'steps', '安装指南', 'installation guide'),
                $s('update', 'steps', '更新指南', 'how to update'),
                $s('requirements', 'table', '系统要求', 'system requirements', $src),
                $s('devices', 'table', '设备兼容列表', 'compatible devices', $src),
                $s('versions', 'timeline', '版本历史', 'version history', $src),
                $s('known_issues', 'checklist', '已知问题', 'known issues', $src),
                $s('troubleshooting', 'faq', '安装问题排查', 'installation problems'),
                $s('verify', 'callout', '下载真实性验证', 'how to make sure the download is official'),
            ],
        ],

        // 十六、安全、公平与信任
        'security' => [
            'label' => '安全中心', 'slug' => $slug('security', 'seguranca', 'seguridad'), 'nav' => 'footer',
            'sections' => [
                $s('center', 'prose', '安全中心', 'how the site keeps players safe', $req),
                $s('phishing', 'checklist', '钓鱼网站识别', 'recognising phishing sites'),
                $s('fake_support', 'callout', '假冒客服识别', 'recognising fake support agents'),
                $s('report', 'steps', '可疑行为举报', 'reporting suspicious activity'),
                $s('vulnerabilities', 'prose', '安全漏洞反馈', 'reporting a security vulnerability'),
            ],
        ],
        'fairness' => [
            'label' => '公平与信任', 'slug' => $slug('fairness', 'jogo-justo', 'juego-justo'), 'nav' => 'footer',
            'sections' => [
                $s('fairness', 'prose', '游戏公平性说明', 'how games stay fair (random number generation)', $req),
                $s('labs', 'prose', '独立测试机构介绍', 'independent testing labs', ['requires' => 'certification']),
                $s('certificates', 'table', '认证资料查询', 'certificates', ['requires' => 'certification']),
                $s('license', 'table', '牌照与监管信息', 'licence and regulator', ['requires' => 'license']),
                $s('operator', 'table', '运营主体查询', 'operating entity', ['requires' => 'company']),
                $s('privacy', 'prose', '个人信息保护说明', 'how personal data is protected'),
            ],
        ],

        // 十七、负责任游戏
        'responsible' => [
            'label' => '负责任游戏', 'slug' => $slug('responsible-gaming', 'jogo-responsavel', 'juego-responsable'), 'nav' => 'footer', 'required' => true, 'max_sections' => 10,
            'sections' => [
                $s('intro', 'prose', '负责任游戏首页', 'our approach to responsible gaming', $req),
                $s('age', 'callout', '年龄与参与资格', 'age and eligibility', $req),
                $s('risks', 'prose', '游戏风险说明', 'risks of gaming'),
                $s('self_assessment', 'checklist', '自我评估', 'questions to check your gaming habits'),
                $s('reminders', 'prose', '时间提醒', 'time reminders'),
                $s('minors', 'prose', '未成年人保护', 'protecting minors'),
                $s('family', 'prose', '家人和朋友支持', 'support for family and friends'),
                $s('get_help', 'prose', '寻求帮助', 'getting help'),
                $s('resources', 'table', '外部支持资源', 'support organisations (name / how to reach)', $src),
                $s('marketing', 'prose', '营销信息退订', 'opting out of marketing messages'),
            ],
        ],

        // 十八、社区与品牌互动
        'community' => [
            'label' => '社区与品牌互动', 'slug' => $slug('community', 'comunidade', 'comunidad'), 'nav' => 'footer',
            'sections' => [
                $s('intro', 'prose', '社区首页', 'the community around the brand', $req),
                $list('announcements', '社区活动', 'community events', 'event', 'tag:community'),
                $s('rules', 'checklist', '社区规则', 'community rules'),
                $s('feedback', 'card_grid', '玩家反馈精选', 'player feedback highlights', $src),
                $s('discussions', 'prose', '游戏主题讨论', 'what players talk about', $src),
                $s('ideas', 'prose', '新功能意见征集', 'how to suggest features'),
                $s('dev_qa', 'faq', '开发者问答', 'developer Q&A', $src),
                $s('wallpapers', 'gallery', '品牌壁纸与素材', 'wallpapers and assets'),
                $s('fan_art', 'card_grid', '粉丝作品展示', 'fan creations', $src),
                $s('socials', 'link_grid', '官方社交平台', 'official social platforms', ['links' => ['official_channels', 'events']]),
            ],
        ],

        // 十九、商务与合作
        'business' => [
            'label' => '商务合作', 'slug' => $slug('business', 'parcerias', 'alianzas'), 'nav' => 'footer',
            'sections' => [
                $s('intro', 'prose', '商务合作首页', 'working with the brand', $req),
                $s('types', 'features', '合作方式', 'ways to partner: game content, providers, technical integration, co-branding, media, creators'),
                $s('process', 'steps', '合作流程', 'how a partnership starts'),
                $s('apply', 'contact', '合作申请', 'how to apply'),
                $s('cases', 'card_grid', '合作案例', 'partnership examples', $src),
                $s('partner_support', 'prose', '合作伙伴支持', 'support for partners'),
                $s('media_kit', 'prose', '媒体资料包', 'media kit'),
                $s('careers', 'prose', '招聘与职业发展', 'careers', $src),
            ],
        ],

        // 二十、法律、隐私与网站服务
        'search' => [
            'label' => '全站搜索', 'slug' => $slug('search', 'buscar', 'buscar'), 'nav' => 'legal', 'required' => true, 'min_words' => 0,
            'sections' => [$s('search', 'site_search', '全站搜索', 'search the whole site', $req)],
        ],
        'terms' => ['label' => '用户协议', 'slug' => $slug('terms-of-service', 'termos-de-servico', 'terminos-de-servicio'), 'nav' => 'legal', 'required' => true, 'min_words' => 0, 'sections' => [$s('legal', 'legal', '用户协议', 'terms of service', $req)]],
        'privacy' => ['label' => '隐私政策', 'slug' => $slug('privacy-policy', 'politica-de-privacidade', 'politica-de-privacidad'), 'nav' => 'legal', 'required' => true, 'min_words' => 0, 'sections' => [$s('legal', 'legal', '隐私政策', 'privacy policy', $req), $s('data_requests', 'steps', '个人数据请求与删除', 'how to request or delete personal data')]],
        'cookies' => ['label' => 'Cookie 政策', 'slug' => $slug('cookie-policy', 'politica-de-cookies', 'politica-de-cookies'), 'nav' => 'legal', 'min_words' => 0, 'sections' => [$s('legal', 'legal', 'Cookie 政策', 'cookie policy', $req), $s('preferences', 'cookie_settings', 'Cookie 偏好设置', 'change cookie preferences')]],
        'bonus_terms' => ['label' => '优惠与活动通用条款', 'slug' => $slug('bonus-terms', 'termos-de-bonus', 'terminos-de-bonos'), 'nav' => 'legal', 'min_words' => 0, 'sections' => [$s('legal', 'legal', '优惠通用条款', 'general promotion terms', $req), $s('event_terms', 'prose', '活动通用条款', 'general event terms')]],
        'game_terms' => ['label' => '游戏使用条款', 'slug' => $slug('game-rules', 'regras-dos-jogos', 'reglas-de-los-juegos'), 'nav' => 'legal', 'min_words' => 0, 'sections' => [$s('legal', 'legal', '游戏使用条款', 'terms for using the games', $req)]],
        'regions' => ['label' => '地区限制', 'slug' => $slug('restricted-regions', 'regioes-restritas', 'regiones-restringidas'), 'nav' => 'legal', 'min_words' => 0, 'sections' => [$s('legal', 'legal', '地区限制说明', 'restricted regions', $req)]],
        'ip' => ['label' => '知识产权声明', 'slug' => $slug('intellectual-property', 'propriedade-intelectual', 'propiedad-intelectual'), 'nav' => 'legal', 'min_words' => 0, 'sections' => [$s('legal', 'legal', '知识产权声明 / 商标与版权', 'intellectual property, trademarks and copyright', $req), $s('third_party', 'prose', '第三方服务说明', 'third-party services used by the site')]],
        'accessibility' => ['label' => '无障碍声明', 'slug' => $slug('accessibility', 'acessibilidade', 'accesibilidad'), 'nav' => 'legal', 'min_words' => 0, 'sections' => [$s('legal', 'legal', '无障碍声明', 'accessibility statement', $req)]],
        'sitemap' => ['label' => '网站地图', 'slug' => $slug('sitemap', 'mapa-do-site', 'mapa-del-sitio'), 'nav' => 'legal', 'required' => true, 'min_words' => 0, 'sections' => [$s('map', 'html_sitemap', '网站地图', 'every page of the site', $req)]],
    ],
];
