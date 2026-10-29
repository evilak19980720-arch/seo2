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
|   requires           app | company | license | certification
|   source             true = only facts from the brand profile or the reference;
|                      the section is left out when the sources say nothing
| "nav": main = header, secondary = dropdown under "parent", footer, legal.
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
    'positionings' => [
        'catalog' => [
            'label' => '游戏目录型',
            'menu' => ['games', 'lobby', 'providers', 'guide', 'blog', 'help', 'about'],
            'children' => ['games' => ['slot_types', 'themes'], 'providers' => ['behind_scenes'], 'guide' => ['academy', 'game_math'], 'help' => ['notices', 'contact'], 'about' => ['brand_story', 'company', 'official_channels']],
            'home' => ['intro', 'categories', 'featured_games', 'new_games', 'hot_games', 'learn', 'faq', 'support'],
        ],
        'brand' => [
            'label' => '品牌展示型',
            'menu' => ['games', 'providers', 'blog', 'help', 'about', 'business'],
            'children' => ['games' => ['themes', 'slot_types', 'lobby'], 'providers' => ['behind_scenes'], 'help' => ['guide', 'notices', 'contact'], 'about' => ['brand_story', 'company', 'press', 'official_channels']],
            'home' => ['intro', 'about_brand', 'hot_games', 'highlights', 'providers', 'articles', 'faq'],
        ],
        'knowledge' => [
            'label' => '知识内容型',
            'menu' => ['games', 'guide', 'academy', 'blog', 'help', 'about'],
            'children' => ['games' => ['slot_types', 'themes', 'providers'], 'academy' => ['game_math'], 'help' => ['notices', 'contact'], 'about' => ['brand_story', 'official_channels']],
            'home' => ['intro', 'start', 'categories', 'featured_games', 'learn', 'articles', 'support'],
        ],
        'promo' => [
            'label' => '优惠活动型',
            'menu' => ['games', 'promotions', 'events', 'blog', 'help', 'about'],
            'children' => ['games' => ['lobby', 'providers', 'slot_types'], 'help' => ['guide', 'notices', 'contact'], 'about' => ['brand_story', 'company', 'official_channels']],
            'home' => ['intro', 'live_events', 'promos', 'hot_games', 'trust', 'latest_notices', 'faq', 'support'],
        ],
    ],
    // Pages every positioning has (footer and legal), and a few more drawn per site.
    'footer_pages' => ['responsible', 'terms', 'privacy', 'cookies', 'regions', 'sitemap', 'search'],
    'footer_optional' => ['security', 'fairness', 'community', 'game_terms', 'ip', 'accessibility', 'status'],
    'footer_optional_count' => [2, 3],
    // The app page joins the menu (before help) when the brand has an app.
    'app_page' => 'download',
    // Sections per content page (home has its own list).
    'page_sections' => [3, 6],
    // Phones first: a fixed bottom bar (home, games, download…) even when the reference has none.
    'mobile_bar' => true,
    'main_nav_order' => ['games', 'lobby', 'promotions', 'events', 'providers', 'news', 'download', 'about', 'help'],
    'optional_page_chance' => 0.6,
    'optional_section_chance' => 0.55,
    'max_sections' => 9,
    'game_types' => array_map(fn ($t) => $t[0], $types),
    'game_themes' => array_map(fn ($t) => $t[0], $themes),

    // Site data shown in lists and on detail pages (/{owner page}/{entry}/); owner = first chosen page.
    'collections' => [
        'game' => ['label' => '游戏', 'owners' => ['games', 'lobby'], 'sections' => [
            $s('details', 'prose', '游戏详情', 'what the game is: theme, look, setting', $req),
            $s('story', 'prose', '游戏背景故事', 'the story behind the game', $src),
            $s('characters', 'prose', '游戏角色介绍', 'the characters of the game', $src),
            $s('visuals', 'prose', '游戏画面展示', 'graphics and art style'),
            $s('audio', 'prose', '游戏音效介绍', 'sound and music'),
            $s('features', 'prose', '游戏特色', 'what makes the game stand out'),
            $s('rules', 'prose', '玩法规则', 'how to play'),
            $s('symbols', 'prose', '符号说明', 'the symbols and what they do'),
            $s('paytable', 'prose', '赔付表说明', 'how to read the paytable', $src),
            $s('mechanics', 'prose', '特殊机制说明', 'special mechanics and bonus features'),
            $s('devices', 'prose', '支持设备', 'devices and browsers the game runs on'),
            $s('faq', 'prose', '游戏常见问题', 'common questions about this game'),
            $s('version', 'prose', '游戏版本说明', 'versions of the game', $src),
            $s('changelog', 'prose', '游戏更新记录', 'updates to the game', $src),
        ], 'blocks' => ['params' => '游戏参数', 'media' => '游戏截图', 'series' => '同系列 / 相似游戏推荐']],
        'provider' => ['label' => '供应商', 'owners' => ['providers'], 'sections' => [
            $s('details', 'prose', '供应商详情', 'who the provider is and its style', $req),
            $s('story', 'prose', '供应商品牌故事', 'the provider\'s story', $src),
            $s('mechanics', 'prose', '供应商特色机制', 'mechanics the provider is known for'),
            $s('studio', 'prose', '研发工作室介绍', 'the studio behind the games', $src),
            $s('interview', 'prose', '供应商访谈', 'interview highlights', $src),
        ], 'blocks' => ['games' => '供应商游戏合集', 'latest' => '供应商新作']],
        'promotion' => ['label' => '优惠', 'owners' => ['promotions'], 'sections' => [
            $s('details', 'prose', '优惠详情', 'what the offer is', $req),
            $s('eligibility', 'prose', '优惠资格说明', 'who can take part'),
            $s('how_to_claim', 'prose', '优惠领取指南', 'how to claim it'),
            $s('usage', 'prose', '优惠使用指南', 'how to use it'),
            $s('validity', 'prose', '优惠有效期', 'when it is valid'),
            $s('games', 'prose', '优惠适用游戏', 'games the offer applies to'),
            $s('limits', 'prose', '优惠限制条件', 'limits and restrictions'),
            $s('terms', 'prose', '优惠完整条款', 'full terms of this offer'),
            $s('faq', 'prose', '优惠常见问题', 'common questions about this offer'),
        ], 'blocks' => []],
        'event' => ['label' => '活动', 'owners' => ['events'], 'sections' => [
            $s('details', 'prose', '活动详情', 'what the event is', $req),
            $s('rules', 'prose', '活动规则', 'rules of this event'),
            $s('progress', 'prose', '活动进度', 'how the event progresses', $src),
            $s('results', 'prose', '活动结果', 'results of the event', $src),
            $s('winners', 'prose', '获奖公示', 'announced winners', $src),
            $s('prizes', 'prose', '奖品领取说明', 'how prizes are claimed'),
            $s('recap', 'prose', '活动精彩回顾', 'highlights of the event', $src),
        ], 'blocks' => []],
        'notice' => ['label' => '公告', 'owners' => ['notices', 'status'], 'sections' => [
            $s('details', 'prose', '公告内容', 'the announcement', $req),
        ], 'blocks' => []],
        'news' => ['label' => '资讯', 'owners' => ['news'], 'sections' => [
            $s('details', 'prose', '资讯正文', 'the news story', $req),
            $s('background', 'prose', '背景信息', 'background and context'),
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
                $list('latest_news', '最新资讯', 'latest news', 'news', 'latest'),
                $s('articles', 'article_list', '最新文章', 'latest articles from the news centre'),
                $s('about_brand', 'prose', '品牌简介', 'who the brand is and what it offers players'),
                $s('start', 'steps', '入门指南', 'how a new visitor gets started, in a few steps'),
                $s('learn', 'link_grid', '游戏知识入口', 'guides and knowledge pages', ['links' => ['guide', 'academy', 'game_math', 'slot_types', 'themes']]),
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

        // 二、游戏介绍
        'games' => [
            'label' => '游戏介绍', 'slug' => $slug('games', 'jogos', 'juegos'), 'nav' => 'main', 'required' => true, 'min_words' => 250,
            'sections' => [
                $s('overview', 'prose', '全部游戏介绍', 'overview of the games on the site and how they differ', $req),
                $list('cards', '全部游戏', 'every game', 'game', 'all', $req),
                $list('hot', '热门游戏介绍', 'popular games', 'game', 'hot'),
                $list('new', '新游戏介绍', 'new games', 'game', 'new'),
                $list('classic', '经典游戏介绍', 'classic games', 'game', 'classic'),
                $list('featured', '编辑精选', 'editor\'s picks', 'game', 'featured'),
                $s('topics', 'card_grid', '游戏专题', 'themed game collections on the site'),
                $list('upcoming', '即将上线游戏', 'games coming soon', 'game', 'upcoming'),
                $s('categories', 'link_grid', '按类型浏览', 'browse by type', ['links' => 'sections:slot_types']),
                $s('faq', 'faq', '游戏常见问题', 'common questions about the games'),
            ],
        ],
        // 四、按 Slots 类型分类
        'slot_types' => [
            'label' => '按 Slots 类型分类', 'slug' => $slug('slot-types', 'tipos-de-slots', 'tipos-de-slots'), 'nav' => 'secondary', 'parent' => 'games', 'max_sections' => 12,
            'sections' => array_merge(
                [$s('intro', 'prose', '类型说明', 'how slot types differ', $req)],
                array_map(fn ($key, $t) => $list($key, $t[0], $t[1], 'game', "type:{$key}"), array_keys($types), $types),
            ),
        ],
        // 五、按游戏主题分类
        'themes' => [
            'label' => '按游戏主题分类', 'slug' => $slug('game-themes', 'temas-de-jogos', 'temas-de-juegos'), 'nav' => 'secondary', 'parent' => 'games', 'max_sections' => 12,
            'sections' => array_merge(
                [$s('intro', 'prose', '主题说明', 'why themes matter when choosing a game', $req)],
                array_map(fn ($key, $t) => $list($key, $t[0], 'games with a '.$t[1].' theme', 'game', "theme:{$key}"), array_keys($themes), $themes),
            ),
        ],

        // 三、游戏大厅
        'lobby' => [
            'label' => '游戏大厅', 'slug' => $slug('game-lobby', 'lobby-de-jogos', 'lobby-de-juegos'), 'nav' => 'main', 'required' => true, 'min_words' => 0,
            'sections' => [
                $s('lobby', 'game_lobby', '全部游戏 / 搜索 / 分类 / 筛选', 'all games with search, tabs and filters', $req),
                $list('mobile', '手机游戏专区', 'games for phones', 'game', 'device:mobile'),
                $list('desktop', '桌面游戏专区', 'games for desktop', 'game', 'device:desktop'),
                $list('upcoming', '新游戏预告', 'upcoming games', 'game', 'upcoming'),
                $list('maintenance', '游戏维护状态', 'games under maintenance', 'notice', 'tag:game_maintenance'),
                $s('about_lobby', 'steps', '大厅使用说明', 'how to use the lobby: tabs, search and filters'),
            ],
        ],

        // 六、游戏机制与知识库
        'academy' => [
            'label' => 'Slots 入门与机制', 'slug' => $slug('slots-guide', 'guia-de-slots', 'guia-de-slots'), 'nav' => 'secondary', 'parent' => 'games', 'max_sections' => 10,
            'sections' => [
                $s('basics', 'prose', 'Slots 入门', 'slots basics', $req),
                $s('glossary', 'glossary', '游戏术语词典', 'glossary of slot terms'),
                $s('reels', 'prose', '卷轴与行列说明', 'reels and rows'),
                $s('paylines', 'prose', '投注线说明', 'paylines'),
                $s('ways', 'prose', 'Ways 赔付说明', 'ways to win'),
                $s('cluster', 'prose', '集群赔付说明', 'cluster pays'),
                $s('wild', 'prose', 'Wild 符号说明', 'wild symbols'),
                $s('scatter', 'prose', 'Scatter 符号说明', 'scatter symbols'),
                $s('free_spins', 'prose', '免费旋转机制说明', 'free spins'),
                $s('respins', 'prose', '重转机制说明', 'respins'),
                $s('multipliers', 'prose', '倍数机制说明', 'multipliers'),
                $s('cascades', 'prose', '连锁消除机制说明', 'cascading reels'),
                $s('expanding', 'prose', '扩展符号说明', 'expanding symbols'),
                $s('bonus_rounds', 'prose', '特殊奖励回合说明', 'bonus rounds'),
                $s('jackpots', 'prose', '奖池机制说明', 'how jackpots work'),
                $s('reading_rules', 'steps', '如何阅读游戏规则', 'how to read a game\'s rules screen'),
                $s('in_game_params', 'steps', '如何查看游戏内参数', 'how to find a game\'s parameters in the game'),
            ],
        ],
        'game_math' => [
            'label' => '概率与公平知识', 'slug' => $slug('rtp-and-volatility', 'rtp-e-volatilidade', 'rtp-y-volatilidad'), 'nav' => 'secondary', 'parent' => 'games',
            'sections' => [
                $s('rng', 'prose', 'RNG 随机数说明', 'random number generators', $req),
                $s('rtp', 'prose', 'RTP 说明', 'return to player explained'),
                $s('volatility', 'prose', '波动性说明', 'volatility explained'),
                $s('hit_frequency', 'prose', '命中频率说明', 'hit frequency'),
                $s('probability', 'callout', '理论概率与短期结果', 'why short-term results differ from theory'),
                $s('myths', 'checklist', '常见游戏误区', 'common myths about slots, corrected'),
            ],
        ],

        // 七、游戏供应商与研发商
        'providers' => [
            'label' => '游戏供应商', 'slug' => $slug('providers', 'provedores', 'proveedores'), 'nav' => 'main', 'min_words' => 200,
            'sections' => [
                $list('list', '供应商列表', 'all game providers', 'provider', 'all', $req),
                $s('intro', 'prose', '供应商介绍', 'what a game provider is and why it matters'),
                $list('latest', '供应商新作', 'latest releases from the providers', 'game', 'new'),
                $s('mechanics', 'prose', '供应商特色机制', 'signature mechanics of the providers'),
                $s('studios', 'prose', '研发工作室介绍', 'the studios behind the games', $src),
            ],
        ],
        'behind_scenes' => [
            'label' => '游戏制作幕后', 'slug' => $slug('behind-the-games', 'bastidores', 'detras-de-los-juegos'), 'nav' => 'secondary', 'parent' => 'providers',
            'sections' => [
                $s('making', 'prose', '游戏制作幕后', 'how the games are made', $src + $req),
                $s('art', 'prose', '美术设计幕后', 'art and design', $src),
                $s('music', 'prose', '音乐制作幕后', 'music and sound', $src),
                $s('dev_log', 'timeline', '开发者日志', 'developer log entries', $src),
                $s('interviews', 'quote', '供应商访谈', 'a quote from a developer interview', $src),
            ],
        ],

        // 八、优惠内容
        'promotions' => [
            'label' => '优惠内容', 'slug' => $slug('promotions', 'promocoes', 'promociones'), 'nav' => 'main', 'required' => true, 'min_words' => 250,
            'sections' => [
                $list('list', '当前优惠', 'promotions running now', 'promotion', 'active', $req),
                $list('new_user', '新用户优惠', 'offers for new players', 'promotion', 'tag:new_user'),
                $list('regular', '常规优惠', 'regular recurring offers', 'promotion', 'tag:regular'),
                $list('festival', '节日优惠', 'holiday and festival offers', 'promotion', 'tag:festival'),
                $list('anniversary', '周年庆优惠', 'anniversary offers', 'promotion', 'tag:anniversary'),
                $list('app_only', 'App 专属优惠', 'app-only offers', 'promotion', 'tag:app', ['requires' => 'app']),
                $list('invite', '专属邀请优惠', 'invitation offers', 'promotion', 'tag:invite'),
                $s('eligibility', 'checklist', '优惠资格说明', 'who can take part in promotions'),
                $s('how_to_claim', 'steps', '优惠领取指南', 'how to claim a promotion step by step'),
                $s('usage', 'steps', '优惠使用指南', 'how to use a promotion'),
                $s('limits', 'table', '优惠限制条件', 'common limits (item / rule)', $src),
                $s('terms', 'prose', '优惠通用条款', 'general promotion terms'),
                $s('faq', 'faq', '优惠常见问题', 'questions about promotions'),
                $list('ended', '已结束优惠', 'promotions that have ended', 'promotion', 'ended'),
            ],
        ],

        // 九、活动中心
        'events' => [
            'label' => '活动中心', 'slug' => $slug('events', 'eventos', 'eventos'), 'nav' => 'main', 'min_words' => 200,
            'sections' => [
                $list('list', '全部活动', 'all events', 'event', 'all', $req),
                $list('ongoing', '正在进行', 'events running now', 'event', 'ongoing'),
                $list('upcoming', '即将开始', 'events starting soon', 'event', 'upcoming'),
                $s('calendar', 'event_calendar', '活动日历', 'event calendar'),
                $list('launches', '新游戏发布活动', 'new game launch events', 'event', 'tag:launch'),
                $list('anniversary', '品牌周年活动', 'anniversary events', 'event', 'tag:anniversary'),
                $list('festival', '节日主题活动', 'holiday events', 'event', 'tag:festival'),
                $list('community', '社区互动活动', 'community events', 'event', 'tag:community'),
                $list('quiz', '游戏知识问答', 'quiz events', 'event', 'tag:quiz'),
                $list('creative', '创意征集活动', 'creative contests', 'event', 'tag:creative'),
                $list('collab', '品牌联名活动', 'collaboration events', 'event', 'tag:collab'),
                $list('live', '直播活动', 'live-stream events', 'event', 'tag:live'),
                $list('offline', '线下活动', 'in-person events', 'event', 'tag:offline'),
                $s('rules', 'checklist', '活动规则', 'general event rules'),
                $s('results', 'prose', '活动结果与获奖公示', 'how results and winners are announced'),
                $s('prizes', 'steps', '奖品领取说明', 'how prizes are claimed'),
                $list('past', '往期活动', 'past events', 'event', 'ended'),
            ],
        ],

        // 十、新手中心
        'guide' => [
            'label' => '新手中心', 'slug' => $slug('beginners-guide', 'guia-para-iniciantes', 'guia-para-principiantes'), 'nav' => 'secondary', 'parent' => 'help', 'max_sections' => 10,
            'sections' => [
                $s('start', 'steps', '新用户入门', 'getting started on the site', $req),
                $s('first_use', 'prose', '首次使用指南', 'your first visit'),
                $s('lobby_guide', 'steps', '游戏大厅使用指南', 'using the game lobby'),
                $s('search_guide', 'prose', '游戏搜索指南', 'finding a game with search and filters'),
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
                $s('promo_faq', 'faq', '优惠问题', 'promotion questions'),
                $s('event_faq', 'faq', '活动问题', 'event questions'),
                $s('app_faq', 'faq', 'App 使用问题', 'app questions', ['requires' => 'app']),
                $s('browser', 'table', '浏览器兼容问题', 'supported browsers (browser / note)'),
                $s('devices', 'faq', '设备兼容问题', 'device compatibility'),
                $s('feedback', 'contact', '意见反馈与在线客服', 'how to send feedback or reach support'),
                $s('game_error', 'steps', '游戏错误反馈', 'how to report a game error'),
                $s('complaints', 'steps', '投诉处理流程', 'how complaints are handled'),
                $s('disputes', 'prose', '争议处理说明', 'how disputes are resolved'),
                $s('hours', 'table', '客服服务时间', 'support hours', $src),
                $s('accessibility', 'prose', '无障碍支持', 'accessibility support'),
                $s('more', 'link_grid', '更多帮助', 'other help pages', ['links' => ['guide', 'notices', 'security', 'contact', 'responsible']]),
            ],
        ],

        // 十二、公告与资讯
        'notices' => [
            'label' => '公告中心', 'slug' => $slug('notices', 'avisos', 'avisos'), 'nav' => 'secondary', 'parent' => 'help', 'min_words' => 0,
            'sections' => [
                $list('all', '平台公告', 'all announcements', 'notice', 'all', $req),
                $list('maintenance', '系统维护公告', 'maintenance notices', 'notice', 'tag:maintenance'),
                $list('restored', '服务恢复公告', 'service restored notices', 'notice', 'tag:restored'),
                $list('launches', '游戏上新公告', 'new game notices', 'notice', 'tag:new_game'),
                $list('removals', '游戏下架公告', 'games removed', 'notice', 'tag:removed'),
                $list('game_updates', '游戏更新公告', 'game update notices', 'notice', 'tag:game_update'),
                $list('rules', '活动规则更新', 'rule changes', 'notice', 'tag:rules'),
                $list('policy', '协议与隐私政策更新', 'terms and privacy updates', 'notice', 'tag:policy'),
                $list('channels', '官方渠道变更', 'official channel changes', 'notice', 'tag:channels'),
                $s('security', 'callout', '安全提醒', 'security reminder for players'),
                $s('anti_fraud', 'checklist', '防诈骗公告', 'how to avoid scams'),
            ],
        ],
        'news' => [
            'label' => '资讯中心', 'slug' => $slug('news', 'noticias', 'noticias'), 'nav' => 'main', 'min_words' => 0,
            'sections' => [
                $list('all', '全部资讯', 'all news', 'news', 'all', $req),
                $list('brand', '品牌新闻', 'brand news', 'news', 'tag:brand'),
                $list('new_games', '游戏上新', 'new game releases', 'news', 'tag:new_games'),
                $list('features', '游戏功能介绍', 'game features', 'news', 'tag:features'),
                $list('updates', '产品更新日志', 'product updates', 'news', 'tag:updates'),
                $list('editorial', '编辑专栏', 'editorial columns', 'news', 'tag:editorial'),
                $list('monthly', '品牌月报', 'monthly reports', 'news', 'tag:monthly'),
                $list('partners', '品牌合作动态', 'partnership news', 'news', 'tag:partners'),
                $list('archive', '资讯归档', 'news archive', 'news', 'latest'),
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
                $s('domains', 'prose', '官方域名查询', 'checking official domains'),
                $s('socials', 'prose', '官方社交账号验证', 'verifying official social accounts'),
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
                $list('announcements', '社区公告', 'community news', 'news', 'tag:community'),
                $s('rules', 'checklist', '社区规则', 'community rules'),
                $s('feedback', 'card_grid', '玩家反馈精选', 'player feedback highlights', $src),
                $s('discussions', 'prose', '游戏主题讨论', 'what players talk about', $src),
                $s('ideas', 'prose', '新功能意见征集', 'how to suggest features'),
                $s('dev_qa', 'faq', '开发者问答', 'developer Q&A', $src),
                $s('wallpapers', 'gallery', '品牌壁纸与素材', 'wallpapers and assets'),
                $s('characters', 'card_grid', '游戏角色图鉴', 'game characters', $src),
                $s('fan_art', 'card_grid', '粉丝作品展示', 'fan creations', $src),
                $s('socials', 'link_grid', '官方社交平台', 'official social platforms', ['links' => ['official_channels', 'news', 'events']]),
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
        'status' => [
            'label' => '服务状态', 'slug' => $slug('service-status', 'status-do-servico', 'estado-del-servicio'), 'nav' => 'footer', 'min_words' => 0,
            'sections' => [
                $s('intro', 'prose', '服务状态', 'where service status and maintenance are announced', $req),
                $list('maintenance', '维护日历', 'scheduled maintenance', 'notice', 'tag:maintenance'),
                $list('restored', '服务恢复', 'services restored', 'notice', 'tag:restored'),
                $s('report', 'contact', '页面错误反馈', 'reporting a broken page'),
            ],
        ],
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
