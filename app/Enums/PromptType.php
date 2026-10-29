<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum PromptType: string implements HasLabel
{
    case SiteMeta = 'site_meta';
    case BrandExtract = 'brand_extract';
    case KeywordExpand = 'keyword_expand';
    case DesignConfig = 'design_config';
    case CustomCss = 'custom_css';
    case PageContent = 'page_content';
    case ArticleGenerate = 'article_generate';
    case ArticlePolish = 'article_polish';
    case ArticleReview = 'article_review';
    case FactCheck = 'fact_check';
    case BrandTranslate = 'brand_translate';
    case SiteData = 'site_data';
    case EntryContent = 'entry_content';
    case UiTranslate = 'ui_translate';
    case BrandComplete = 'brand_complete';
    case SiteReview = 'site_review';

    public function getLabel(): string
    {
        return match ($this) {
            self::SiteMeta => '站点标题与描述',
            self::BrandExtract => '品牌资料提取',
            self::KeywordExpand => '关键词扩展',
            self::DesignConfig => '设计配置',
            self::CustomCss => '自定义 CSS',
            self::PageContent => '核心页面文案',
            self::ArticleGenerate => '文章生成',
            self::ArticlePolish => '文章润色',
            self::ArticleReview => 'AI 审核',
            self::FactCheck => '事实核对',
            self::BrandTranslate => '品牌资料转语言',
            self::SiteData => '站点数据（游戏/优惠/活动…）',
            self::EntryContent => '详情页文案',
            self::UiTranslate => '界面文字翻译',
            self::BrandComplete => '品牌资料补全（参考网站）',
            self::SiteReview => 'AI 质检（美观 + SEO）',
        };
    }
}
