<?php

declare(strict_types=1);

namespace nglasl\misdirection;

use SilverStripe\Forms\GridField\GridField_HTMLProvider;

/**
 *	The testing interface used to view the link mapping recursion stack.
 *	@author Nathan Glasl <nathan@symbiote.com.au>
 */

class MisdirectionTesting implements GridField_HTMLProvider
{
    /**
     *	Render the URL input and test button.
     */
    public function getHTMLFragments($gridfield): array
    {
        return [
            'before' => '<div class="misdirection-testing admin">
				<div><strong>' . htmlspecialchars(_t(self::class . '.TEST_LINK_MAPPING', 'Test a redirect')) . '</strong></div>
				<div class="wrapper">
					<input type="text" class="text w-50 url" spellcheck="false"/>
					<span role="button" class="btn btn-notice font-icon-switch test disabled" tabindex="0">
						<span class="btn__title">' . htmlspecialchars(_t(self::class . '.TEST_LINK_MAPPING_BUTTON_TEXT', 'Test')) . '</span>
					</span>
				</div>
				<div class="results"></div>
			</div>'
        ];
    }

}
