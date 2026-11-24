<?php
/**
 * @package     VikRentCar
 * @subpackage  com_vikrentcar
 * @author      Alessio Gaggii - e4j - Extensionsforjoomla.com
 * @copyright   Copyright (C) 2018 e4j - Extensionsforjoomla.com. All rights reserved.
 * @license     GNU General Public License version 2 or later; see LICENSE
 * @link        https://vikwp.com
 */

defined('ABSPATH') or die('No script kiddies please!');

// import Joomla view library
jimport('joomla.application.component.view');

class VikRentCarViewPmsreports extends JViewVikRentCar
{
    public function display($tpl = null)
    {
        // Set the toolbar
        $this->addToolBar();

        /**
         * @wponly - trigger back up of extendable files
         *
         * This code can also be kept in Joomla as it won't be executed.
         */
        if (VRCPlatformDetection::isWordPress()) {
            VikRentCarLoader::import('update.manager');
            VikRentCarUpdateManager::triggerExtendableClassesBackup('report');
        }

        $dbo = JFactory::getDbo();

        // load all the available PMS Reports
		list($report_objs, $country_objs) = VRCReportLoader::getInstance()->getDrivers();

		// get countries involved
		$countries = VRCReportLoader::getInstance()->getInvolvedCountries();

        $this->report_objs  = $report_objs;
        $this->country_objs = $country_objs;
        $this->countries    = $countries;

        // Display the template
        parent::display($tpl);
    }

    /**
     * Sets the toolbar
     */
    protected function addToolBar()
    {
        JToolBarHelper::title(JText::_('VRCMAINPMSREPORTSTITLE'), 'vikrentcar');
        JToolBarHelper::cancel('canceldash', JText::_('VRBACK'));
        JToolBarHelper::spacer();
    }
}
