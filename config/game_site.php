<?php

/*
| Game site preset: a pool of sections. Each site keeps the required pages
| and a random (seeded) selection of the rest; inside every page, required
| sections plus a random selection of the optional ones. "requires" names a
| condition (accounts, payments, app, company, license, certification) the
| site must meet, so features it does not have are never described.
| "nav": main = header menu, secondary = dropdown under "parent", footer/legal = footer only.
*/

return [
    'label' => '游戏品牌站',
    'schema' => 'VideoGame',
    'sectioned' => true,
    'responsible_notice' => true,
    'main_nav_max' => 6,
    'optional_page_chance' => 0.65,
    'optional_section_chance' => 0.6,
    // Data shown in lists and on detail pages (/{owner page}/{entry}/), owner = first chosen page.
    'collections' => [
        'game' => ['label' => '游戏', 'owners' => ['games', 'lobby'], 'sections' => [
            ['key' => 'details', 'label' => '游戏详情', 'topic' => 'what the game is about (theme, look, setting)', 'required' => true],
            ['key' => 'features', 'label' => '游戏特色与玩法规则', 'topic' => 'features and how to play'],
            ['key' => 'symbols', 'label' => '特殊符号与奖励机制', 'topic' => 'special symbols and bonus mechanics'],
        ], 'blocks' => ['params' => '游戏参数说明', 'media' => '游戏截图与视频', 'series' => '同系列游戏推荐']],
        'provider' => ['label' => '供应商', 'owners' => ['providers'], 'sections' => [
            ['key' => 'details', 'label' => '供应商介绍', 'topic' => 'who the provider is and its style', 'required' => true],
        ], 'blocks' => ['games' => '旗下游戏', 'latest' => '最新上线作品']],
        'promotion' => ['label' => '优惠', 'owners' => ['promotions'], 'sections' => [
            ['key' => 'details', 'label' => '优惠详情', 'topic' => 'what the offer is', 'required' => true],
            ['key' => 'how_to_claim', 'label' => '领取方式', 'topic' => 'how to claim it'],
            ['key' => 'terms', 'label' => '优惠条款', 'topic' => 'terms of this offer'],
        ], 'blocks' => []],
        'event' => ['label' => '活动', 'owners' => ['events'], 'sections' => [
            ['key' => 'details', 'label' => '活动详情', 'topic' => 'what the event is', 'required' => true],
            ['key' => 'rules', 'label' => '活动规则', 'topic' => 'rules of this event'],
            ['key' => 'results', 'label' => '活动结果', 'topic' => 'how results are announced'],
        ], 'blocks' => []],
        'notice' => ['label' => '公告', 'owners' => ['notices'], 'sections' => [
            ['key' => 'details', 'label' => '公告内容', 'topic' => 'the announcement', 'required' => true],
        ], 'blocks' => []],
        'news' => ['label' => '资讯', 'owners' => ['news'], 'sections' => [
            ['key' => 'details', 'label' => '资讯正文', 'topic' => 'the news story', 'required' => true],
        ], 'blocks' => []],
    ],
    'pages' => [
        'home' => [
            'label' => '首页',
            'slug' => ['en' => ''],
            'nav' => 'none',
            'required' => true,
            'min_words' => 300,
            'sections' => [
                ['key' => 'intro', 'block' => 'hero', 'label' => '品牌介绍', 'topic' => 'brand introduction: who we are and what players find here', 'required' => true],
                ['key' => 'hot_games', 'block' => 'entry_list', 'label' => '热门游戏推荐', 'topic' => 'popular games', 'collection' => 'game', 'filter' => 'hot'],
                ['key' => 'new_games', 'block' => 'entry_list', 'label' => '新游戏推荐', 'topic' => 'newly added games', 'collection' => 'game', 'filter' => 'new'],
                ['key' => 'promos', 'block' => 'entry_list', 'label' => '优惠精选', 'topic' => 'featured promotions', 'collection' => 'promotion', 'filter' => 'featured'],
                ['key' => 'live_events', 'block' => 'entry_list', 'label' => '正在进行的活动', 'topic' => 'events running now', 'collection' => 'event', 'filter' => 'ongoing'],
                ['key' => 'latest_notices', 'block' => 'entry_list', 'label' => '最新公告', 'topic' => 'latest announcements', 'collection' => 'notice', 'filter' => 'latest'],
                ['key' => 'support', 'block' => 'cta', 'label' => '客服入口', 'topic' => 'how to reach customer support', 'link' => 'help'],
            ],
        ],
        'games' => [
            'label' => '游戏介绍',
            'slug' => ['en' => 'games', 'pt' => 'jogos', 'es' => 'juegos'],
            'nav' => 'main',
            'required' => true,
            'min_words' => 250,
            'sections' => [
                ['key' => 'overview', 'block' => 'prose', 'label' => '游戏总览', 'topic' => 'overview of the slot games on the site and how they differ', 'required' => true],
                ['key' => 'cards', 'block' => 'entry_list', 'label' => '各类 Slots 游戏卡片', 'topic' => 'card-style introduction of every slot game', 'collection' => 'game', 'filter' => 'all', 'required' => true],
            ],
        ],
        'lobby' => [
            'label' => '游戏大厅',
            'slug' => ['en' => 'game-lobby', 'pt' => 'lobby-de-jogos', 'es' => 'lobby-de-juegos'],
            'nav' => 'main',
            'required' => true,
            'min_words' => 0,
            'sections' => [
                ['key' => 'lobby', 'block' => 'game_lobby', 'label' => '全部游戏 / 搜索与筛选', 'topic' => 'all games with search, tabs and filters', 'required' => true],
                ['key' => 'about_lobby', 'block' => 'prose', 'label' => '大厅使用说明', 'topic' => 'how to find games in the lobby (tabs, providers, themes, mechanics, recently played, favourites)'],
            ],
        ],
        'promotions' => [
            'label' => '优惠内容',
            'slug' => ['en' => 'promotions', 'pt' => 'promocoes', 'es' => 'promociones'],
            'nav' => 'main',
            'required' => true,
            'min_words' => 250,
            'sections' => [
                ['key' => 'list', 'block' => 'entry_list', 'label' => '优惠列表', 'topic' => 'all current promotions', 'collection' => 'promotion', 'filter' => 'active', 'required' => true],
                ['key' => 'new_user', 'block' => 'entry_list', 'label' => '新用户优惠', 'topic' => 'offers for new players', 'collection' => 'promotion', 'filter' => 'tag:new_user'],
                ['key' => 'regular', 'block' => 'entry_list', 'label' => '常规优惠', 'topic' => 'regular recurring offers', 'collection' => 'promotion', 'filter' => 'tag:regular'],
                ['key' => 'festival', 'block' => 'entry_list', 'label' => '节日优惠', 'topic' => 'holiday and festival offers', 'collection' => 'promotion', 'filter' => 'tag:festival'],
                ['key' => 'how_to_claim', 'block' => 'steps', 'label' => '优惠领取说明', 'topic' => 'how to claim a promotion step by step'],
                ['key' => 'terms', 'block' => 'prose', 'label' => '优惠条款', 'topic' => 'general promotion terms'],
                ['key' => 'ended', 'block' => 'entry_list', 'label' => '已结束优惠', 'topic' => 'promotions that have ended', 'collection' => 'promotion', 'filter' => 'ended'],
            ],
        ],
        'events' => [
            'label' => '活动中心',
            'slug' => ['en' => 'events', 'pt' => 'eventos', 'es' => 'eventos'],
            'nav' => 'main',
            'min_words' => 250,
            'sections' => [
                ['key' => 'list', 'block' => 'entry_list', 'label' => '活动列表', 'topic' => 'all events', 'collection' => 'event', 'filter' => 'all', 'required' => true],
                ['key' => 'upcoming', 'block' => 'entry_list', 'label' => '即将开始', 'topic' => 'events starting soon', 'collection' => 'event', 'filter' => 'upcoming'],
                ['key' => 'ongoing', 'block' => 'entry_list', 'label' => '正在进行', 'topic' => 'events running now', 'collection' => 'event', 'filter' => 'ongoing'],
                ['key' => 'past', 'block' => 'entry_list', 'label' => '往期活动', 'topic' => 'past events', 'collection' => 'event', 'filter' => 'ended'],
                ['key' => 'rules', 'block' => 'prose', 'label' => '活动规则', 'topic' => 'general event rules'],
                ['key' => 'results', 'block' => 'prose', 'label' => '活动结果公告', 'topic' => 'how event results and winners are announced'],
            ],
        ],
        'guide' => [
            'label' => '新手指南',
            'slug' => ['en' => 'guide', 'pt' => 'guia', 'es' => 'guia'],
            'nav' => 'secondary',
            'parent' => 'help',
            'sections' => [
                ['key' => 'register_login', 'block' => 'prose', 'label' => '注册与登录指南', 'topic' => 'registration and login guide', 'requires' => 'accounts'],
                ['key' => 'account_settings', 'block' => 'prose', 'label' => '账户设置指南', 'topic' => 'account settings guide', 'requires' => 'accounts'],
                ['key' => 'basics', 'block' => 'prose', 'label' => 'Slots 基础玩法', 'topic' => 'slots basics: reels, paylines, bets, spins', 'required' => true],
                ['key' => 'glossary', 'block' => 'faq', 'label' => '游戏术语解释', 'topic' => 'glossary of slot terms (question = term, answer = definition)'],
                ['key' => 'rtp', 'block' => 'prose', 'label' => 'RTP 与波动性说明', 'topic' => 'RTP and volatility explained'],
                ['key' => 'reading_rules', 'block' => 'prose', 'label' => '游戏规则阅读指南', 'topic' => 'how to read a game rules / paytable screen'],
                ['key' => 'demo', 'block' => 'prose', 'label' => '试玩说明', 'topic' => 'how demo / free play works'],
            ],
        ],
        'providers' => [
            'label' => '游戏供应商',
            'slug' => ['en' => 'providers', 'pt' => 'provedores', 'es' => 'proveedores'],
            'nav' => 'main',
            'min_words' => 200,
            'sections' => [
                ['key' => 'list', 'block' => 'entry_list', 'label' => '供应商列表', 'topic' => 'all game providers', 'collection' => 'provider', 'filter' => 'all', 'required' => true],
                ['key' => 'intro', 'block' => 'prose', 'label' => '供应商介绍', 'topic' => 'what a game provider is and why it matters'],
                ['key' => 'latest', 'block' => 'entry_list', 'label' => '最新上线作品', 'topic' => 'latest releases from the providers', 'collection' => 'game', 'filter' => 'new'],
            ],
        ],
        'news' => [
            'label' => '资讯中心',
            'slug' => ['en' => 'news', 'pt' => 'noticias', 'es' => 'noticias'],
            'nav' => 'main',
            'min_words' => 0,
            'sections' => [
                ['key' => 'all', 'block' => 'entry_list', 'label' => '全部资讯', 'topic' => 'all news', 'collection' => 'news', 'filter' => 'all', 'required' => true],
                ['key' => 'brand', 'block' => 'entry_list', 'label' => '品牌新闻', 'topic' => 'brand news', 'collection' => 'news', 'filter' => 'tag:brand'],
                ['key' => 'new_games', 'block' => 'entry_list', 'label' => '游戏上新', 'topic' => 'new game releases', 'collection' => 'news', 'filter' => 'tag:new_games'],
                ['key' => 'features', 'block' => 'entry_list', 'label' => '游戏功能介绍', 'topic' => 'game feature introductions', 'collection' => 'news', 'filter' => 'tag:features'],
                ['key' => 'updates', 'block' => 'entry_list', 'label' => '产品更新', 'topic' => 'product updates', 'collection' => 'news', 'filter' => 'tag:updates'],
                ['key' => 'partners', 'block' => 'entry_list', 'label' => '品牌合作动态', 'topic' => 'partnership news', 'collection' => 'news', 'filter' => 'tag:partners'],
            ],
        ],
        'notices' => [
            'label' => '公告中心',
            'slug' => ['en' => 'notices', 'pt' => 'avisos', 'es' => 'avisos'],
            'nav' => 'secondary',
            'min_words' => 0,
            'parent' => 'help',
            'sections' => [
                ['key' => 'all', 'block' => 'entry_list', 'label' => '平台公告', 'topic' => 'all announcements', 'collection' => 'notice', 'filter' => 'all', 'required' => true],
                ['key' => 'maintenance', 'block' => 'entry_list', 'label' => '系统维护通知', 'topic' => 'system maintenance notices', 'collection' => 'notice', 'filter' => 'tag:maintenance'],
                ['key' => 'game_maintenance', 'block' => 'entry_list', 'label' => '游戏维护通知', 'topic' => 'game maintenance notices', 'collection' => 'notice', 'filter' => 'tag:game_maintenance'],
                ['key' => 'rule_changes', 'block' => 'entry_list', 'label' => '规则调整通知', 'topic' => 'rule change notices', 'collection' => 'notice', 'filter' => 'tag:rules'],
                ['key' => 'security', 'block' => 'prose', 'label' => '安全提醒', 'topic' => 'security reminders for players'],
                ['key' => 'official_channels', 'block' => 'prose', 'label' => '官方渠道与防冒充说明', 'topic' => 'official channels and how to recognise impostors'],
            ],
        ],
        'help' => [
            'label' => '帮助中心',
            'slug' => ['en' => 'help', 'pt' => 'ajuda', 'es' => 'ayuda'],
            'nav' => 'main',
            'required' => true,
            'sections' => [
                ['key' => 'faq', 'block' => 'faq', 'label' => '常见问题', 'topic' => 'frequently asked questions', 'required' => true],
                ['key' => 'account', 'block' => 'faq', 'label' => '账户与登录', 'topic' => 'account and login questions', 'requires' => 'accounts'],
                ['key' => 'verification', 'block' => 'faq', 'label' => '身份验证', 'topic' => 'identity verification questions', 'requires' => 'accounts'],
                ['key' => 'loading', 'block' => 'faq', 'label' => '游戏加载与运行', 'topic' => 'game loading and performance questions'],
                ['key' => 'promo_faq', 'block' => 'faq', 'label' => '优惠与活动问题', 'topic' => 'promotion and event questions'],
                ['key' => 'payments', 'block' => 'faq', 'label' => '支付与交易问题', 'topic' => 'payment and transaction questions', 'requires' => 'payments'],
                ['key' => 'feedback', 'block' => 'contact', 'label' => '问题反馈', 'topic' => 'how to send feedback'],
                ['key' => 'complaints', 'block' => 'steps', 'label' => '投诉处理流程', 'topic' => 'complaint handling process'],
            ],
        ],
        'about' => [
            'label' => '关于我们',
            'slug' => ['en' => 'about-us', 'pt' => 'sobre-nos', 'es' => 'sobre-nosotros'],
            'nav' => 'secondary',
            'required' => true,
            'parent' => 'help',
            'sections' => [
                ['key' => 'story', 'block' => 'prose', 'label' => '品牌故事', 'topic' => 'brand story', 'required' => true],
                ['key' => 'company', 'block' => 'prose', 'label' => '公司介绍', 'topic' => 'company introduction', 'requires' => 'company'],
                ['key' => 'operator', 'block' => 'prose', 'label' => '运营主体', 'topic' => 'operating entity', 'requires' => 'company'],
                ['key' => 'history', 'block' => 'steps', 'label' => '品牌发展历程', 'topic' => 'brand milestones in order (only ones from the sources)'],
                ['key' => 'channels', 'block' => 'prose', 'label' => '官方渠道', 'topic' => 'official channels'],
                ['key' => 'business', 'block' => 'prose', 'label' => '商务合作', 'topic' => 'business cooperation'],
                ['key' => 'contact', 'block' => 'contact', 'label' => '联系我们', 'topic' => 'contact us'],
            ],
        ],
        'fairness' => [
            'label' => '安全与公平',
            'slug' => ['en' => 'security-and-fairness', 'pt' => 'seguranca-e-justica', 'es' => 'seguridad-y-justicia'],
            'nav' => 'footer',
            'sections' => [
                ['key' => 'account_security', 'block' => 'prose', 'label' => '账户安全', 'topic' => 'account security', 'requires' => 'accounts'],
                ['key' => 'privacy', 'block' => 'prose', 'label' => '个人信息保护', 'topic' => 'personal information protection', 'required' => true],
                ['key' => 'fairness', 'block' => 'prose', 'label' => '游戏公平性说明', 'topic' => 'game fairness explained (random number generation)'],
                ['key' => 'certification', 'block' => 'prose', 'label' => '可核验的测试与认证信息', 'topic' => 'testing and certification information (only verifiable facts)', 'requires' => 'certification'],
                ['key' => 'license', 'block' => 'prose', 'label' => '可核验的牌照信息', 'topic' => 'licence information (only verifiable facts)', 'requires' => 'license'],
            ],
        ],
        'responsible' => [
            'label' => '负责任游戏',
            'slug' => ['en' => 'responsible-gaming', 'pt' => 'jogo-responsavel', 'es' => 'juego-responsable'],
            'nav' => 'footer',
            'required' => true,
            'sections' => [
                ['key' => 'age', 'block' => 'prose', 'label' => '年龄与参与资格说明', 'topic' => 'age and eligibility', 'required' => true],
                ['key' => 'risks', 'block' => 'prose', 'label' => '游戏风险说明', 'topic' => 'risks of gaming'],
                ['key' => 'limits', 'block' => 'prose', 'label' => '时间与金额限制', 'topic' => 'time and spending limits', 'requires' => 'payments'],
                ['key' => 'self_exclusion', 'block' => 'prose', 'label' => '暂停与自我排除', 'topic' => 'pausing and self-exclusion', 'requires' => 'accounts'],
                ['key' => 'resources', 'block' => 'prose', 'label' => '帮助资源', 'topic' => 'where to get help'],
            ],
        ],
        'account' => [
            'label' => '用户中心',
            'slug' => ['en' => 'account', 'pt' => 'minha-conta', 'es' => 'mi-cuenta'],
            'nav' => 'footer',
            'requires' => 'accounts',
            'sections' => [
                ['key' => 'overview', 'block' => 'prose', 'label' => '用户中心说明', 'topic' => 'what players manage in their account: profile, verification, security, favourites, history, messages, support records', 'required' => true],
            ],
        ],
        'download' => [
            'label' => '下载中心',
            'slug' => ['en' => 'download', 'pt' => 'baixar', 'es' => 'descargar'],
            'nav' => 'main',
            'requires' => 'app',
            'sections' => [
                ['key' => 'intro', 'block' => 'download', 'label' => 'App 介绍 / 官方下载入口', 'topic' => 'app introduction and official download links', 'required' => true],
                ['key' => 'install', 'block' => 'steps', 'label' => '安装指南', 'topic' => 'installation guide'],
                ['key' => 'requirements', 'block' => 'prose', 'label' => '系统要求', 'topic' => 'system requirements'],
                ['key' => 'changelog', 'block' => 'prose', 'label' => '版本更新记录', 'topic' => 'version history (only versions from the sources)'],
                ['key' => 'faq', 'block' => 'faq', 'label' => '安装常见问题', 'topic' => 'installation questions'],
            ],
        ],
        'terms' => [
            'label' => '用户协议',
            'slug' => ['en' => 'terms-of-service', 'pt' => 'termos-de-servico', 'es' => 'terminos-de-servicio'],
            'nav' => 'legal',
            'required' => true,
            'min_words' => 0,
            'sections' => [
                ['key' => 'legal', 'block' => 'legal', 'label' => '用户协议', 'topic' => '用户协议', 'required' => true],
            ],
        ],
        'privacy' => [
            'label' => '隐私政策',
            'slug' => ['en' => 'privacy-policy', 'pt' => 'politica-de-privacidade', 'es' => 'politica-de-privacidad'],
            'nav' => 'legal',
            'required' => true,
            'min_words' => 0,
            'sections' => [
                ['key' => 'legal', 'block' => 'legal', 'label' => '隐私政策', 'topic' => '隐私政策', 'required' => true],
            ],
        ],
        'cookies' => [
            'label' => 'Cookie 政策',
            'slug' => ['en' => 'cookie-policy', 'pt' => 'politica-de-cookies', 'es' => 'politica-de-cookies'],
            'nav' => 'legal',
            'min_words' => 0,
            'sections' => [
                ['key' => 'legal', 'block' => 'legal', 'label' => 'Cookie 政策', 'topic' => 'Cookie 政策', 'required' => true],
            ],
        ],
        'bonus_terms' => [
            'label' => '优惠通用条款',
            'slug' => ['en' => 'bonus-terms', 'pt' => 'termos-de-bonus', 'es' => 'terminos-de-bonos'],
            'nav' => 'legal',
            'min_words' => 0,
            'sections' => [
                ['key' => 'legal', 'block' => 'legal', 'label' => '优惠通用条款', 'topic' => '优惠通用条款', 'required' => true],
            ],
        ],
        'regions' => [
            'label' => '地区限制说明',
            'slug' => ['en' => 'restricted-regions', 'pt' => 'regioes-restritas', 'es' => 'regiones-restringidas'],
            'nav' => 'legal',
            'min_words' => 0,
            'sections' => [
                ['key' => 'legal', 'block' => 'legal', 'label' => '地区限制说明', 'topic' => '地区限制说明', 'required' => true],
            ],
        ],
        'sitemap' => [
            'label' => '网站地图',
            'slug' => ['en' => 'sitemap', 'pt' => 'mapa-do-site', 'es' => 'mapa-del-sitio'],
            'nav' => 'legal',
            'required' => true,
            'min_words' => 0,
            'sections' => [
                ['key' => 'map', 'block' => 'html_sitemap', 'label' => '网站地图', 'topic' => 'every page of the site', 'required' => true],
            ],
        ],
    ],
];
