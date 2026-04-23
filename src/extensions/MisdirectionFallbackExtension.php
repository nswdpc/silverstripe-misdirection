<?php

namespace nglasl\misdirection;

use SilverStripe\CMS\Model\SiteTree;
use SilverStripe\Core\Config\Config;
use SilverStripe\Forms\DropdownField;
use SilverStripe\Forms\FieldList;
use SilverStripe\Forms\HeaderField;
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
        /** @var \SilverStripe\SiteConfig\SiteConfig|\SilverStripe\CMS\Model\SiteTree $owner */
        $owner = $this->getOwner();
        if (class_exists(SiteConfig::class) && $owner instanceof SiteConfig) {
            return $owner->updateFields($fields);
        }

        return null;
    }

    public function updateSettingsFields($fields)
    {
        // This extension only exists for SiteTree.
        /** @var \SilverStripe\SiteConfig\SiteConfig|\SilverStripe\CMS\Model\SiteTree $owner */
        $owner = $this->getOwner();
        if (class_exists(SiteTree::class) && $owner instanceof SiteTree) {
            return $owner->updateFields($fields);
        }
    }

    public function updateFields($fields)
    {

        Requirements::javascript('nglasl/silverstripe-misdirection: client/javascript/misdirection-fallback.js');

        // Update any fields that are displayed when not viewing a page.
        $tab = 'Root.Misdirection';
        $options = [
            'Nearest' => 'Nearest Parent',
            'This' => 'This Page',
            'URL' => 'URL'
        ];

        /** @var \SilverStripe\SiteConfig\SiteConfig|\SilverStripe\CMS\Model\SiteTree $owner */
        $owner = $this->getOwner();
        if (class_exists(SiteConfig::class) && $owner instanceof SiteConfig) {
            $tab = 'Root.Pages';
            unset($options['This']);
        }

        // Retrieve the fallback mapping selection.
        $fields->addFieldToTab(
            $tab,
            HeaderField::create(
                'FallbackHeader',
                'Fallback'
            )
        );
        $fields->addFieldToTab(
            $tab,
            DropdownField::create(
                'Fallback',
                'To',
                $options
            )->addExtraClass('fallback')
            ->setHasEmptyDefault(true)
            ->setDescription('This will be used when children result in a <strong>page not found</strong>')
        );
        $fields->addFieldToTab(
            $tab,
            TextField::create(
                'FallbackLink',
                'URL'
            )->addExtraClass('fallback-link')
            ->setDescription('This requires the <strong>HTTP/S</strong> scheme for an external URL')
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
            $owner->FallbackResponseCode = 303;
        }

        $fields->addFieldToTab(
            $tab,
            DropdownField::create(
                'FallbackResponseCode',
                'Response Code',
                $selection
            )->addExtraClass('fallback-response-code')
        );

        // Allow extension customisation.
        $owner->extend('updateMisdirectionFallbackExtensionFields', $fields);
    }

}
