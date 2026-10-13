<?php

namespace nglasl\misdirection;

use SilverStripe\CMS\Model\SiteTree;
use SilverStripe\Core\Config\Config;
use SilverStripe\Forms\DropdownField;
use SilverStripe\Forms\FieldList;
use SilverStripe\Forms\CompositeField;
use SilverStripe\Forms\TextField;
use SilverStripe\Core\Extension;
use SilverStripe\SiteConfig\SiteConfig;
use SilverStripe\View\Requirements;

/**
 * This extension allows pages to have a fallback mapping for children that result in a page not found.
 * @author Nathan Glasl <nathan@symbiote.com.au>
 * @property ?string $Fallback
 * @property ?string $FallbackLink
 * @property int $FallbackResponseCode
 * @extends \SilverStripe\Core\Extension<((\SilverStripe\CMS\Model\SiteTree & static) | (\SilverStripe\SiteConfig\SiteConfig & static))>
 */
class MisdirectionFallbackExtension extends Extension
{
    private static array $db = [
        'Fallback' => 'Varchar(255)',
        'FallbackLink' => 'Varchar(255)',
        'FallbackResponseCode' => 'Int'
    ];

    private static array $defaults = [
        'FallbackResponseCode' => 303
    ];

    /**
     *	Display the appropriate fallback fields.
     */
    public function updateCMSFields(FieldList $fields)
    {
        $fields->removeByName(['Fallback','FallbackLink','FallbackResponseCode']);
        /** @var \SilverStripe\SiteConfig\SiteConfig|\SilverStripe\CMS\Model\SiteTree $owner */
        $owner = $this->getOwner();
        if (class_exists(SiteConfig::class) && $owner instanceof SiteConfig) {
            return $this->updateMisdirectionFields($fields);
        }

        return null;
    }

    public function updateSettingsFields($fields)
    {
        $fields->removeByName(['Fallback','FallbackLink','FallbackResponseCode']);
        // This extension only exists for SiteTree.
        /** @var \SilverStripe\SiteConfig\SiteConfig|\SilverStripe\CMS\Model\SiteTree $owner */
        $owner = $this->getOwner();
        if (class_exists(SiteTree::class) && $owner instanceof SiteTree) {
            return $this->updateMisdirectionFields($fields);
        }
    }

    protected function updateMisdirectionFields($fields)
    {

        Requirements::javascript('nglasl/silverstripe-misdirection: client/javascript/misdirection-fallback.js');

        // Update any fields that are displayed when not viewing a page.
        $tab = 'Root.Redirection';
        $options = [
            'Nearest' => 'Nearest parent page',
            'This' => 'This page',
            'URL' => 'A website address'
        ];

        /** @var \SilverStripe\SiteConfig\SiteConfig|\SilverStripe\CMS\Model\SiteTree $owner */
        $owner = $this->getOwner();
        if (class_exists(SiteConfig::class) && $owner instanceof SiteConfig) {
            $tab = 'Root.Pages';
            unset($options['This']);
        }

        $holderField = CompositeField::create([])->setTitle('Child path fallback handling');
        // Retrieve the fallback mapping selection.
        $holderField->push(
            DropdownField::create(
                'Fallback',
                'To',
                $options
            )->addExtraClass('fallback')
            ->setHasEmptyDefault(true)
            ->setDescription('This target is used when a request to a child page of this page results in a 404: Page Not Found')
        );
        $holderField->push(
            TextField::create(
                'FallbackLink',
                'URL'
            )->addExtraClass('fallback-link')
            ->setDescription('<strong>HTTP/S</strong> prefix is required for an external URL')
        );

        // Retrieve the response code selection.
        $responses = Config::inst()->get(MisDirectionRequestProcessor::class, 'status_codes');
        $selection = [];
        foreach ($responses as $code => $description) {
            if (($code >= 300) && ($code < 400)) {
                $selection[$code] = "{$code}: {$description}";
            }
        }

        if (!$owner->FallbackResponseCode) {
            $owner->FallbackResponseCode = 301;
        }

        $holderField->push(
            DropdownField::create(
                'FallbackResponseCode',
                'Response code',
                $selection
            )->addExtraClass('fallback-response-code')
            ->setDescription('This field instructs a web browser or robot how to handle the redirect. If unsure, choose 301.')
        );

        $fields->addFieldToTab($tab, $holderField);

        // Allow extension customisation.
        $owner->extend('updateMisdirectionFallbackExtensionFields', $fields);
    }

}
