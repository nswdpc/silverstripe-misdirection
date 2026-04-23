<?php

namespace nglasl\misdirection;

use SilverStripe\CMS\Controllers\CMSPageSettingsController;
use SilverStripe\CMS\Model\SiteTree;
use SilverStripe\Control\Controller;
use SilverStripe\Control\Director;
use SilverStripe\Core\Config\Config;
use SilverStripe\Forms\HeaderField;
use SilverStripe\Forms\TextField;
use SilverStripe\ORM\ArrayList;
use SilverStripe\Core\Extension;
use SilverStripe\ORM\ValidationResult;

/**
 * This extension provides vanity mapping directly from a page, and automatically creates the appropriate link mappings when replacing the default automated URL handling.
 * @todo move this to a separate CMS module that requires this core module?
 * @author Nathan Glasl <nathan@symbiote.com.au>
 * @property int $VanityMappingID
 * @method \nglasl\misdirection\LinkMapping VanityMapping()
 * @extends \SilverStripe\Core\Extension<(\SilverStripe\CMS\Model\SiteTree & static)>
 */
class SiteTreeMisdirectionExtension extends Extension
{
    /**
     * This provides link mapping customisation directly from a page.
     */
    private static array $has_one = [
        'VanityMapping' => LinkMapping::class
    ];

    public function updateSettingsFields($fields)
    {
        /** @var \SilverStripe\CMS\Model\SiteTree $page */
        $page = $this->getOwner();
        $vanityMapping = $page->VanityMapping();
        if($vanityMapping && $vanityMapping->isInDB()) {

            $fields->addFieldToTab(
                'Root.Misdirection',
                HeaderField::create(
                    'VanityHeader',
                    'Vanity'
                )
            );

            if (($page instanceof SiteTree) && $vanityMapping->RedirectPageID != $page->ID) {
                // The mapping may have been pointed to another page.
                $page->VanityMappingID = 0;
            }

            $fields->addFieldToTab(
                'Root.Misdirection',
                TextField::create(
                    'VanityURL',
                    'URL',
                    $vanityMapping->MappedLink
                )->setDescription(
                    'Mappings with higher priority will take precedence over this'
                )
            );

        }

        // Allow extension customisation.
        $page->extend('updateSiteTreeMisdirectionExtensionSettingsFields', $fields);
    }

    public function validate(ValidationResult $result): ValidationResult
    {

        /** @var \SilverStripe\CMS\Model\SiteTree $page */
        $page = $this->getOwner();
        $vanityMapping = $page->VanityMapping();

        // Retrieve the vanity mapping URL, where this is only possible using the POST variable.
        $controller = Controller::curr();
        $url = $controller ? $controller->getRequest()->postVar('VanityURL') : null;
        if(!$url) {
            $url = $vanityMapping->MappedLink;
        }

        if (!$url) {
            return $result;
        }

        // Determine whether another vanity mapping already exists.
        $existing = LinkMapping::get()->filter([
            'MappedLink' => $url,
            'RedirectType' => 'Page',
            'RedirectPageID:not' => [ 0, $page->ID ]
        ])->first();

        if (class_exists(SiteTree::class) && class_exists(CMSPageSettingsController::class) && $result->isValid() && $existing && ($page = $existing->getRedirectPage())) {
            $link = Controller::join_links(CMSPageSettingsController::singleton()->Link('show'), $page->ID);
            $result->addError("Vanity URL {$link}' already exists", ValidationResult::TYPE_ERROR);
        }

        // Allow extension.
        $page->extend('validateSiteTreeMisdirectionExtension', $result);
        return $result;
    }

    /**
     *Update the corresponding vanity mapping.
     */
    public function onBeforeWrite()
    {

        /** @var \SilverStripe\CMS\Model\SiteTree $page */
        $page = $this->getOwner();
        $vanityMapping = $page->VanityMapping();

        // Retrieve the vanity mapping URL, where this is only possible using the POST variable.
        $controller = Controller::curr();
        $url = $controller ? $controller->getRequest()->postVar('VanityURL') : null;
        if(!$url) {
            $url = $vanityMapping->MappedLink;
        }

        $mappingExists = $vanityMapping->isInDB();

        // Determine whether the vanity mapping URL has been updated.
        if ($url && $mappingExists) {
            if ($vanityMapping->MappedLink !== $url) {
                // Update the corresponding vanity mapping.
                $vanityMapping->MappedLink = $url;
                $vanityMapping->write();
            }
        } elseif ($url) {
            // Determine whether the vanity mapping URL has been defined.
            // Instantiate the vanity mapping.
            $mapping = singleton(MisdirectionService::class)->createPageMapping($url, $page->ID, 2);
            $page->VanityMappingID = $mapping->ID;
        } elseif ($mappingExists) {
            // Determine whether the vanity mapping URL has been removed.
            // Remove the corresponding vanity mapping.
            $vanityMapping->delete();
        }
    }

    /**
     *	Update link mappings when replacing the default automated URL handling.
     */
    public function onAfterWrite()
    {

        // Determine whether the default automated URL handling has been replaced.
        if (class_exists(SiteTree::class) && Config::inst()->get(MisDirectionRequestProcessor::class, 'replace_default')) {

            /** @var \SilverStripe\CMS\Model\SiteTree $page */
            $page = $this->getOwner();

            // Determine whether the URL segment or parent ID has been updated.
            $changed = $page->getChangedFields();
            if ((isset($changed['URLSegment']['before']) && isset($changed['URLSegment']['after']) && ($changed['URLSegment']['before'] != $changed['URLSegment']['after'])) || (isset($changed['ParentID']['before']) && isset($changed['ParentID']['after']) && ($changed['ParentID']['before'] != $changed['ParentID']['after']))) {

                // The link mappings should only be created for existing pages.
                $url = ($changed['URLSegment']['before'] ?? $page->URLSegment);
                if (!str_starts_with((string) $url, 'new-')) {
                    // Determine the page URL.
                    $parentID = ($changed['ParentID']['before'] ?? $page->ParentID);
                    $parent = SiteTree::get_one(SiteTree::class, "SiteTree.ID = {$parentID}");
                    while ($parent) {
                        $url = Controller::join_links($parent->URLSegment, $url);
                        $parent = SiteTree::get_one(SiteTree::class, "SiteTree.ID = {$parent->ParentID}");
                    }

                    // Instantiate a link mapping for this page.
                    singleton(MisdirectionService::class)->createPageMapping($url, $page->ID);

                    // Purge any link mappings that point back to the same page.
                    $page->regulateMappings(($page->Link() === Director::baseURL()) ? Controller::join_links(Director::baseURL(), MisdirectionService::getHomeSegment()) : $page->Link(), $page->ID);

                    // Recursively create link mappings for any children.
                    $children = $page->AllChildrenIncludingDeleted();
                    if ($children->count()) {
                        $page->recursiveMapping($url, $children);
                    }
                }
            }
        }
    }

    /**
     *	Determine whether link mappings need to be updated when removing this page.
     */
    public function onAfterDelete()
    {

        /** @var \SilverStripe\CMS\Model\SiteTree $page */
        $page = $this->getOwner();

        // Determine whether this page has been completely removed.
        if (Config::inst()->get(MisDirectionRequestProcessor::class, 'replace_default') && !$page->isPublished() && !$page->isOnDraft()) {

            // Convert any link mappings that are directly associated with this page.
            $mappings = LinkMapping::get()->filter([
                'RedirectType' => 'Page',
                'RedirectPageID' => $page->ID
            ]);
            foreach ($mappings as $mapping) {
                $mapping->RedirectType = 'Link';
                $mapping->RedirectLink = Director::makeRelative(($page->Link() === Director::baseURL()) ? Controller::join_links(Director::baseURL(), MisdirectionService::getHomeSegment()) : $page->Link());
                $mapping->write();
            }
        }
    }

    /**
     *	Purge any link mappings that point back to the same page.
     */
    public function regulateMappings(string $pageLink, int $pageID)
    {

        LinkMapping::get()->filter([
            'MappedLink' => MisdirectionService::unify_URL(Director::makeRelative($pageLink)),
            'RedirectType' => 'Page',
            'RedirectPageID' => $pageID
        ])->removeAll();
    }

    /**
     *	Recursively create link mappings for any children.
     */
    public function recursiveMapping(string $baseURL, ArrayList $children)
    {

        foreach ($children as $child) {

            // Instantiate a link mapping for this page.
            $url = Controller::join_links($baseURL, $child->URLSegment);
            singleton(MisdirectionService::class)->createPageMapping($url, $child->ID);

            // Purge any link mappings that point back to the same page.
            $this->getOwner()->regulateMappings(($child->Link() === Director::baseURL()) ? Controller::join_links(Director::baseURL(), MisdirectionService::getHomeSegment()) : $child->Link(), $child->ID);

            // Recursively create link mappings for any children.
            $recursiveChildren = $child->AllChildrenIncludingDeleted();
            if ($recursiveChildren->count()) {
                $this->getOwner()->recursiveMapping($url, $recursiveChildren);
            }
        }
    }

}
