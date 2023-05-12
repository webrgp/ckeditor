<?php

namespace craft\ckeditor;

use Craft;
use craft\base\ElementInterface;
use craft\ckeditor\events\DefineLinkOptionsEvent;
use craft\ckeditor\events\ModifyConfigEvent;
use craft\ckeditor\web\assets\ckeditor\CkeditorAsset;
use craft\elements\Asset;
use craft\elements\Category;
use craft\elements\Entry;
use craft\helpers\ArrayHelper;
use craft\helpers\Html;
use craft\helpers\Json;
use craft\htmlfield\events\ModifyPurifierConfigEvent;
use craft\htmlfield\HtmlField;
use craft\htmlfield\HtmlFieldData;
use craft\models\CategoryGroup;
use craft\models\ImageTransform;
use craft\models\Section;
use craft\models\Volume;
use craft\web\View;
use HTMLPurifier_Config;
use Illuminate\Support\Collection;
use yii\base\InvalidArgumentException;

/**
 * CKEditor field type
 *
 * @author Pixel & Tonic, Inc. <support@pixelandtonic.com>
 */
class Field extends HtmlField
{
    /**
     * @event ModifyPurifierConfigEvent The event that is triggered when creating HTML Purifier config
     *
     * Plugins can get notified when HTML Purifier config is being constructed.
     *
     * ```php
     * use craft\ckeditor\Field;
     * use craft\htmlfield\ModifyPurifierConfigEvent;
     * use HTMLPurifier_AttrDef_Text;
     * use yii\base\Event;
     *
     * Event::on(
     *     Field::class,
     *     Field::EVENT_MODIFY_PURIFIER_CONFIG,
     *     function(ModifyPurifierConfigEvent $event) {
     *          // ...
     *     }
     * );
     * ```
     */
    public const EVENT_MODIFY_PURIFIER_CONFIG = 'modifyPurifierConfig';

    /**
     * @event DefineLinkOptionsEvent The event that is triggered when registering the link options for the field.
     * @since 3.0.0
     */
    public const EVENT_DEFINE_LINK_OPTIONS = 'defineLinkOptions';

    /**
     * @event ModifyConfigEvent The event that is triggered when registering the link options for the field.
     * @since 3.1.0
     */
    public const EVENT_MODIFY_CONFIG = 'modifyConfig';

    /**
     * @inheritdoc
     */
    public static function displayName(): string
    {
        return 'CKEditor';
    }

    /**
     * @var string|null The CKEditor config UUID
     * @since 3.0.0
     */
    public ?string $ckeConfig = null;

    /**
     * @var bool Whether the word count should be shown below the field.
     * @since 3.2.0
     */
    public bool $showWordCount = false;

    /**
     * @var string|array|null The volumes that should be available for image selection.
     * @since 1.2.0
     */
    public string|array|null $availableVolumes = '*';

    /**
     * @var string|array|null The transforms available when selecting an image.
     * @since 1.2.0
     */
    public string|array|null $availableTransforms = '*';

    /**
     * @var string|null The default transform to use.
     */
    public ?string $defaultTransform = null;

    /**
     * @var bool Whether to enable source editing for non-admin users.
     * @since 3.3.0
     */
    public bool $enableSourceEditingForNonAdmins = false;

    /**
     * @var bool Whether to show volumes the user doesn’t have permission to view.
     * @since 1.2.0
     */
    public bool $showUnpermittedVolumes = false;

    /**
     * @var bool Whether to show files the user doesn’t have permission to view, per the
     * “View files uploaded by other users” permission.
     * @since 1.2.0
     */
    public bool $showUnpermittedFiles = false;

    /**
     * @inheritdoc
     */
    public function __construct($config = [])
    {
        unset(
            $config['initJs'],
            $config['removeInlineStyles'],
            $config['removeEmptyTags'],
            $config['removeNbsp'],
        );

        parent::__construct($config);
    }

    /**
     * @inheritdoc
     */
    public function getSettingsHtml(): ?string
    {
        $view = Craft::$app->getView();

        $volumeOptions = [];
        foreach (Craft::$app->getVolumes()->getAllVolumes() as $volume) {
            if ($volume->getFs()->hasUrls) {
                $volumeOptions[] = [
                    'label' => $volume->name,
                    'value' => $volume->uid,
                ];
            }
        }

        $transformOptions = [];
        foreach (Craft::$app->getImageTransforms()->getAllTransforms() as $transform) {
            $transformOptions[] = [
                'label' => $transform->name,
                'value' => $transform->uid,
            ];
        }

        return $view->renderTemplate('ckeditor/_field-settings.twig', [
            'field' => $this,
            'purifierConfigOptions' => $this->configOptions('htmlpurifier'),
            'volumeOptions' => $volumeOptions,
            'transformOptions' => $transformOptions,
            'defaultTransformOptions' => array_merge([
                [
                    'label' => Craft::t('ckeditor', 'No transform'),
                    'value' => null,
                ],
            ], $transformOptions),
        ]);
    }

    /**
     * @inheritdoc
     */
    public function getSettings(): array
    {
        $settings = parent::getSettings();

        // Cleanup
        unset(
            $settings['removeInlineStyles'],
            $settings['removeEmptyTags'],
            $settings['removeNbsp'],
        );

        return $settings;
    }

    /**
     * @inheritdoc
     */
    protected function inputHtml(mixed $value, ElementInterface $element = null): string
    {
        $view = Craft::$app->getView();
        $view->registerAssetBundle(CkeditorAsset::class);

        $ckeConfig = $this->_getCkeConfig();

        if ($this->defaultTransform) {
            $defaultTransform = Craft::$app->getImageTransforms()->getTransformByUid($this->defaultTransform);
        } else {
            $defaultTransform = null;
        }

        // Toolbar cleanup
        $toolbar = array_merge($ckeConfig->toolbar);

        if (!$this->enableSourceEditingForNonAdmins && !Craft::$app->getUser()->getIsAdmin()) {
            ArrayHelper::removeValue($toolbar, 'sourceEditing');
        }

        $toolbar = array_values($toolbar);

        $id = Html::id($this->handle);
        $idJs = Json::encode($view->namespaceInputId($id));
        $wordCountId = "$id-counts";
        $wordCountIdJs = Json::encode($view->namespaceInputId($wordCountId));

        $baseConfig = [
            'defaultTransform' => $defaultTransform?->handle,
            'elementSiteId' => $element?->siteId,
            'heading' => [
                'options' => [
                    [
                        'model' => 'paragraph',
                        'title' => 'Paragraph',
                        'class' => 'ck-heading_paragraph',
                    ],
                    ...array_map(fn(int $level) => [
                        'model' => "heading$level",
                        'view' => "h$level",
                        'title' => "Heading $level",
                        'class' => "ck-heading_heading$level",
                    ], $ckeConfig->headingLevels ?: []),
                ],
            ],
            'image' => [
                'toolbar' => [
                    'toggleImageCaption',
                    'imageTextAlternative',
                ],
            ],
            'language' => [
                'ui' => Craft::$app->language,
                'content' => $element?->getSite()->language ?? Craft::$app->language,
            ],
            'linkOptions' => $this->_linkOptions($element),
            'table' => [
                'contentToolbar' => [
                    'tableRow',
                    'tableColumn',
                    'mergeTableCells',
                ],
            ],
            'toolbar' => $toolbar,
            'transforms' => $this->_transforms(),
            'ui' => [
                'viewportOffset' => ['top' => 50],
            ],
        ];

        // Give plugins/modules a chance to modify the config
        $event = new ModifyConfigEvent([
            'baseConfig' => $baseConfig,
            'ckeConfig' => $ckeConfig,
        ]);
        $this->trigger(self::EVENT_MODIFY_CONFIG, $event);

        if (isset($ckeConfig->options)) {
            // translate the placeholder text
            if (isset($ckeConfig->options['placeholder']) && is_string($ckeConfig->options['placeholder'])) {
                $ckeConfig->options['placeholder'] = Craft::t('site', $ckeConfig->options['placeholder']);
            }

            $configOptionsJs = Json::encode($ckeConfig->options);
        } elseif (isset($ckeConfig->js)) {
            $configOptionsJs = <<<JS
(() => {
  $ckeConfig->js
})()
JS;
        } else {
            $configOptionsJs = '{}';
        }

        $baseConfigJs = Json::encode($event->baseConfig);
        $showWordCountJs = Json::encode($this->showWordCount);

        $view->registerJs(<<<JS
(($) => {
  const config = Object.assign($baseConfigJs, $configOptionsJs);
  const extraRemovePlugins = [];
  if ($showWordCountJs) {
    if (typeof config.wordCount === 'undefined') {
      config.wordCount = {};
    }
    const onUpdate = config.wordCount.onUpdate || (() => {});
    config.wordCount.onUpdate = (stats) => {
      const statText = [];
      if (config.wordCount.displayWords || typeof config.wordCount.displayWords === 'undefined') {
        statText.push(Craft.t('ckeditor', '{num, number} {num, plural, =1{word} other{words}}', {
          num: stats.words,
        }));
      }
      if (config.wordCount.displayCharacters) { // false by default
        statText.push(Craft.t('ckeditor', '{num, number} {num, plural, =1{character} other{characters}}', {
          num: stats.characters,
        }));
      }
      $('#' + $wordCountIdJs).html(Craft.escapeHtml(statText.join(', ')) || '&nbsp;');
      onUpdate(stats);
    }
  } else {
    extraRemovePlugins.push('WordCount');
  }
  if (extraRemovePlugins.length) {
    if (typeof config.removePlugins === 'undefined') {
      config.removePlugins = [];
    }
    config.removePlugins.push(...extraRemovePlugins);
  }
  Ckeditor.create($idJs, config).then((editor) => {
  });
})(jQuery)
JS,
            View::POS_END,
        );

        if ($ckeConfig->css) {
            $view->registerCss($ckeConfig->css);
        }

        $html = Html::textarea($this->handle, $this->prepValueForInput($value, $element), [
            'id' => $id,
            'class' => 'hidden',
        ]);

        if ($this->showWordCount) {
            $html .= Html::tag('div', '&nbps;', [
                'id' => $wordCountId,
                'class' => ['ck-word-count', 'light', 'smalltext'],
            ]);
        }

        return Html::tag('div', $html, [
            'class' => array_filter([
                $this->showWordCount ? 'ck-with-show-word-count' : null,
            ]),
        ]);
    }

    /**
     * @inheritdoc
     */
    public function getStaticHtml(mixed $value, ElementInterface $element): string
    {
        return Html::tag('div', $this->prepValueForInput($value, $element) ?: '&nbsp;');
    }

    /**
     * @inheritdoc
     */
    protected function purifierConfig(): HTMLPurifier_Config
    {
        $purifierConfig = parent::purifierConfig();

        // Give plugins a chance to modify the HTML Purifier config, or add new ones
        $event = new ModifyPurifierConfigEvent([
            'config' => $purifierConfig,
        ]);

        $this->trigger(self::EVENT_MODIFY_PURIFIER_CONFIG, $event);

        return $event->config;
    }

    /**
     * @inheritdoc
     */
    protected function prepValueForInput($value, ?ElementInterface $element): string
    {
        if ($value instanceof HtmlFieldData) {
            $value = $value->getRawContent();
        }

        if ($value !== null) {
            // Replace NBSP chars with entities, and remove XHTML formatting from  self-closing HTML elements,
            // so CKEditor doesn’t need to normalize them and cause the input value to change
            // (https://github.com/craftcms/cms/issues/13112)
            $pairs = [
                ' ' => '&nbsp;',
            ];
            foreach (array_keys(Html::$voidElements) as $tag) {
                $pairs["<$tag />"] = "<$tag>";
            }
            $value = strtr($value, $pairs);

            // Redactor to CKEditor syntax for <figure>
            // (https://github.com/craftcms/ckeditor/issues/96)
            $value = $this->_translateFromRedactor($value);
        }

        return parent::prepValueForInput($value, $element);
    }

    /**
     * @inheritdoc
     */
    public function serializeValue(mixed $value, ?ElementInterface $element = null): mixed
    {
        if ($value instanceof HtmlFieldData) {
            $value = $value->getRawContent();
        }

        if ($value !== null) {
            // Redactor to CKEditor syntax for <figure>
            // (https://github.com/craftcms/ckeditor/issues/96)
            $value = $this->_translateFromRedactor($value);
        }

        return parent::serializeValue($value, $element);
    }

    /**
     * "Translate" Redactor's <figure> syntax to what CKEditor understands
     * Redactor syntax for images is: <figure><img...
     * Redactor syntax for videos is: <figure><iframe...
     *
     * For CKEditor to understand that <figure> contains an image, it has to have class="image"
     * For CKEditor to understand that <figure> contains a video, it has to have class="media"
     * Additionally, if mediaEmbed -> previewsInData is set to true, video syntax will be:
     * <figure class="media"><div data-oembed-url="<URL>"><iframe...
     * without it, it will be
     * <figure class="media"><oembed...
     *
     * @param string $value
     * @return string
     */
    private function _translateFromRedactor(string $value): string
    {
        $offset = 0;
        // keep going through the field $value, looking for <figure><img and <figure><iframe
        while (preg_match('/<figure\b[^>]*>.*(<img|<iframe)/i', $value, $openMatch, PREG_OFFSET_CAPTURE, $offset)) {
            $figureEndOffset = $openMatch[0][1] + strlen($openMatch[0][0]);
            if (!preg_match('/<\/figure>/', $value, $closeMatch, PREG_OFFSET_CAPTURE, $figureEndOffset)) {
                break;
            }

            $search = $openMatch[0][0];
            $replace = null;
            // if we have a <figure> with <img in it, and it doesn't have an image class
            if (str_contains($search, '<img') && !preg_match('/(\s|")image(\s|")/', $openMatch[0][0])) {
                // add it in and keep all other existing classes
                $replace = preg_replace('/(?=class)((class=")([^"]*)")/', '$2image $3"', $openMatch[0][0]);
            // if we have a <figure> with <iframe in it, and it doesn't have a media class
            } elseif (str_contains($search, '<iframe') && !preg_match('/(\s|")media(\s|")/', $openMatch[0][0])) {
                // add it in and keep all other existing classes
                $replace = preg_replace('/(?=class)((class=")([^"]*)")/', '$2media $3"', $openMatch[0][0]);
            }

            // if we found a replacement
            if ($replace !== null) {
                // part of the $value that contains the <figure> we just found
                $part1 = substr($value, 0, $figureEndOffset);
                // part of the $value after the <figure> we just found
                $part2 = substr($value, $figureEndOffset);

                // replace the "redactor" <figure> with the "ckeditor" one
                $part1 = str_replace($search, $replace, $part1);
                $value = $part1 . $part2;
            }

            $offset = $closeMatch[0][1] + strlen($closeMatch[0][0]);
        }

        // get cke config so that we know how to handle videos
        $ckeConfig = $this->_getCkeConfig();

        // if config is not set with previewsInData: true, wrap the iframe if <div data-oembed-url>
        if (isset($ckeConfig->options['mediaEmbed']['previewsInData']) && $ckeConfig->options['mediaEmbed']['previewsInData'] === true) {
            // not sure about the hardcoded https in the replacement, but CKEditor insists on having the protocol and Redactor insists on just "//"
            $value = preg_replace(
                '/(<figure\b[^>]*>)(<iframe\b[^>]*)(src="([^"]*)")(.*)(<\/iframe>)(<\/figure>)/i',
                '$1<div data-oembed-url="https:$4">$2$3$5$6</div>$7',
                $value);
        } else {
            // otherwise, change iframe to oembed
            // not sure about the hardcoded https in the replacement, but CKEditor insists on having the protocol and Redactor insists on just "//"
            $value = preg_replace(
                '/(<figure\b[^>]*>)(<iframe\b)([^>]*)(src=")(.*)(<\/iframe>)/i',
                '$1<oembed$3url="https:$5</oembed>',
                $value
            );
        }

        return $value;
    }

    /**
     * Return CKE config based on uid or default
     *
     * @return CkeConfig
     */
    private function _getCkeConfig(): CkeConfig
    {
        $ckeConfig = null;
        if ($this->ckeConfig) {
            try {
                $ckeConfig = Plugin::getInstance()->getCkeConfigs()->getByUid($this->ckeConfig);
            } catch (InvalidArgumentException) {
            }
        }
        if (!$ckeConfig) {
            $ckeConfig = new CkeConfig();
        }

        return $ckeConfig;
    }

    /**
     * Returns the link options available to the field.
     *
     * Each link option is represented by an array with the following keys:
     * - `label` (required) – the user-facing option label that appears in the Link dropdown menu
     * - `elementType` (required) – the element type class that the option should be linking to
     * - `sources` (optional) – the sources that the user should be able to select elements from
     * - `criteria` (optional) – any specific element criteria parameters that should limit which elements the user can select
     *
     * @param ElementInterface|null $element The element the field is associated with, if there is one
     * @return array
     */
    private function _linkOptions(?ElementInterface $element = null): array
    {
        $linkOptions = [];

        $sectionSources = $this->_sectionSources($element);
        $categorySources = $this->_categorySources($element);
        $volumeSources = $this->_volumeSources();

        if (!empty($sectionSources)) {
            $linkOptions[] = [
                'label' => Craft::t('ckeditor', 'Link to an entry'),
                'elementType' => Entry::class,
                'refHandle' => Entry::refHandle(),
                'sources' => $sectionSources,
                'criteria' => ['uri' => ':notempty:'],
            ];
        }

        if (!empty($categorySources)) {
            $linkOptions[] = [
                'label' => Craft::t('ckeditor', 'Link to a category'),
                'elementType' => Category::class,
                'refHandle' => Category::refHandle(),
                'sources' => $categorySources,
            ];
        }

        if (!empty($volumeSources)) {
            $criteria = [];
            if ($this->showUnpermittedFiles) {
                $criteria['uploaderId'] = null;
            }
            $linkOptions[] = [
                'label' => Craft::t('ckeditor', 'Link to an asset'),
                'elementType' => Asset::class,
                'refHandle' => Asset::refHandle(),
                'sources' => $volumeSources,
                'criteria' => $criteria,
            ];
        }

        // Give plugins a chance to add their own
        $event = new DefineLinkOptionsEvent([
            'linkOptions' => $linkOptions,
        ]);
        $this->trigger(self::EVENT_DEFINE_LINK_OPTIONS, $event);
        $linkOptions = $event->linkOptions;

        // Fill in any missing ref handles
        foreach ($linkOptions as &$linkOption) {
            if (!isset($linkOption['refHandle'])) {
                /** @var class-string<ElementInterface> $class */
                $class = $linkOption['elementType'];
                $linkOption['refHandle'] = $class::refHandle() ?? $class;
            }
        }

        return $linkOptions;
    }

    /**
     * Returns the available section sources.
     *
     * @param ElementInterface|null $element The element the field is associated with, if there is one
     * @return array
     */
    private function _sectionSources(?ElementInterface $element = null): array
    {
        $sources = [];
        $sections = Craft::$app->getSections()->getAllSections();
        $showSingles = false;

        // Get all sites
        $sites = Craft::$app->getSites()->getAllSites();

        foreach ($sections as $section) {
            if ($section->type === Section::TYPE_SINGLE) {
                $showSingles = true;
            } elseif ($element) {
                $sectionSiteSettings = $section->getSiteSettings();
                foreach ($sites as $site) {
                    if (isset($sectionSiteSettings[$site->id]) && $sectionSiteSettings[$site->id]->hasUrls) {
                        $sources[] = 'section:' . $section->uid;
                    }
                }
            }
        }

        if ($showSingles) {
            array_unshift($sources, 'singles');
        }

        if (!empty($sources)) {
            array_unshift($sources, '*');
        }

        return $sources;
    }

    /**
     * Returns the available category sources.
     *
     * @param ElementInterface|null $element The element the field is associated with, if there is one
     * @return array
     */
    private function _categorySources(?ElementInterface $element = null): array
    {
        if (!$element) {
            return [];
        }

        return Collection::make(Craft::$app->getCategories()->getAllGroups())
            ->filter(fn(CategoryGroup $group) => $group->getSiteSettings()[$element->siteId]?->hasUrls ?? false)
            ->map(fn(CategoryGroup $group) => "group:$group->uid")
            ->values()
            ->all();
    }

    /**
     * Returns the available volume sources.
     *
     * @return string[]
     */
    private function _volumeSources(): array
    {
        if (!$this->availableVolumes) {
            return [];
        }

        $volumes = Collection::make(Craft::$app->getVolumes()->getAllVolumes());

        if (is_array($this->availableVolumes)) {
            $volumes = $volumes->filter(fn(Volume $volume) => in_array($volume->uid, $this->availableVolumes));
        }

        if (!$this->showUnpermittedVolumes) {
            $userService = Craft::$app->getUser();
            $volumes = $volumes->filter(fn(Volume $volume) => $userService->checkPermission("viewAssets:$volume->uid"));
        }

        return $volumes
            ->map(fn(Volume $volume) => "volume:$volume->uid")
            ->values()
            ->all();
    }

    /**
     * Get available transforms.
     *
     * @return array
     */
    private function _transforms(): array
    {
        if (!$this->availableTransforms) {
            return [];
        }

        $transforms = Collection::make(Craft::$app->getImageTransforms()->getAllTransforms());

        if (is_array($this->availableTransforms)) {
            $transforms = $transforms->filter(fn(ImageTransform $transform) => in_array($transform->uid, $this->availableTransforms));
        }

        return $transforms->map(fn(ImageTransform $transform) => [
            'handle' => $transform->handle,
            'name' => $transform->name,
        ])->values()->all();
    }
}
