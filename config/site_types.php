<?php

/*
| Site type presets. Each preset decides which core pages a site gets and the
| default block composition used when the AI design config omits a page.
| Slugs are keyed by language (first part of the locale); "en" is the fallback.
*/

$legal = [
    'privacy' => ['label' => '隐私政策', 'slug' => ['pt' => 'politica-de-privacidade', 'es' => 'politica-de-privacidad', 'en' => 'privacy-policy'], 'blocks' => ['legal']],
    'terms' => ['label' => '服务条款', 'slug' => ['pt' => 'termos-de-servico', 'es' => 'terminos-de-servicio', 'en' => 'terms-of-service'], 'blocks' => ['legal']],
];

return [
    'game' => [
        'label' => '游戏品牌站',
        'schema' => 'VideoGame',
        // Age / play-responsibly line in the footer.
        'responsible_notice' => true,
        'pages' => [
            'home' => ['label' => '首页', 'slug' => ['en' => ''], 'blocks' => ['hero', 'stats', 'features', 'card_grid', 'steps', 'gallery', 'faq', 'download', 'article_list']],
            'product' => ['label' => '游戏介绍', 'slug' => ['pt' => 'sobre-o-jogo', 'es' => 'sobre-el-juego', 'en' => 'about-the-game'], 'blocks' => ['hero', 'features', 'card_grid', 'specs', 'cta']],
            'guide' => ['label' => '玩法指南', 'slug' => ['pt' => 'guia/como-jogar', 'es' => 'guia/como-jugar', 'en' => 'guide/how-to-play'], 'blocks' => ['prose', 'steps', 'cta']],
            'media' => ['label' => '截图与视频', 'slug' => ['pt' => 'imagens-e-videos', 'es' => 'imagenes-y-videos', 'en' => 'screenshots-and-videos'], 'blocks' => ['gallery', 'cta']],
            'updates' => ['label' => '更新公告', 'slug' => ['pt' => 'novidades', 'es' => 'novedades', 'en' => 'updates'], 'blocks' => ['article_list']],
            'faq' => ['label' => 'FAQ', 'slug' => ['pt' => 'perguntas-frequentes', 'es' => 'preguntas-frecuentes', 'en' => 'faq'], 'blocks' => ['faq', 'cta']],
            'about' => ['label' => '关于', 'slug' => ['pt' => 'sobre-nos', 'es' => 'sobre-nosotros', 'en' => 'about-us'], 'blocks' => ['prose']],
            'contact' => ['label' => '联系', 'slug' => ['pt' => 'contato', 'es' => 'contacto', 'en' => 'contact'], 'blocks' => ['contact']],
        ] + $legal,
    ],

    'export' => [
        'label' => '外贸产品站',
        'schema' => 'Product',
        'pages' => [
            'home' => ['label' => '首页', 'slug' => ['en' => ''], 'blocks' => ['hero', 'stats', 'features', 'card_grid', 'gallery', 'specs', 'faq', 'cta', 'article_list']],
            'product' => ['label' => '产品详情', 'slug' => ['pt' => 'produto', 'es' => 'producto', 'en' => 'product'], 'blocks' => ['hero', 'features', 'gallery', 'cta']],
            'specs' => ['label' => '规格参数', 'slug' => ['pt' => 'especificacoes', 'es' => 'especificaciones', 'en' => 'specifications'], 'blocks' => ['specs', 'cta']],
            'applications' => ['label' => '应用场景', 'slug' => ['pt' => 'aplicacoes', 'es' => 'aplicaciones', 'en' => 'applications'], 'blocks' => ['prose', 'gallery', 'cta']],
            'faq' => ['label' => 'FAQ', 'slug' => ['pt' => 'perguntas-frequentes', 'es' => 'preguntas-frecuentes', 'en' => 'faq'], 'blocks' => ['faq', 'cta']],
            'about' => ['label' => '关于工厂', 'slug' => ['pt' => 'sobre-a-fabrica', 'es' => 'sobre-la-fabrica', 'en' => 'about-our-factory'], 'blocks' => ['prose', 'gallery']],
            'contact' => ['label' => '询盘联系', 'slug' => ['pt' => 'solicitar-orcamento', 'es' => 'solicitar-cotizacion', 'en' => 'request-a-quote'], 'blocks' => ['contact']],
        ] + $legal,
    ],

    'brand' => [
        'label' => '通用品牌站',
        'schema' => 'SoftwareApplication',
        'pages' => [
            'home' => ['label' => '首页', 'slug' => ['en' => ''], 'blocks' => ['hero', 'features', 'steps', 'gallery', 'faq', 'download', 'article_list']],
            'product' => ['label' => '产品介绍', 'slug' => ['pt' => 'produto', 'es' => 'producto', 'en' => 'product'], 'blocks' => ['hero', 'features', 'specs', 'cta']],
            'faq' => ['label' => 'FAQ', 'slug' => ['pt' => 'perguntas-frequentes', 'es' => 'preguntas-frecuentes', 'en' => 'faq'], 'blocks' => ['faq', 'cta']],
            'about' => ['label' => '关于', 'slug' => ['pt' => 'sobre-nos', 'es' => 'sobre-nosotros', 'en' => 'about-us'], 'blocks' => ['prose']],
            'contact' => ['label' => '联系', 'slug' => ['pt' => 'contato', 'es' => 'contacto', 'en' => 'contact'], 'blocks' => ['contact']],
        ] + $legal,
    ],
];
