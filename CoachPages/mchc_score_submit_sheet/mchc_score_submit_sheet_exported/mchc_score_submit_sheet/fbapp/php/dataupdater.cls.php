<?php

/**
 * CoffeeCup Software's Web Form Builder.
 *
 * Class for data reporting. Used for S-Drive control panel. 
 *
 *
 * @version $Revision: 2456 $
 * @author Cees de Gruijter
 * @category FB
 * @copyright Copyright (c) 2012 CoffeeCup Software, Inc. (http://www.coffeecup.com/)
 */


class DataUpdater extends DashboardData
{
	public function __construct ()
	{
		parent::__construct();
	}


	// this is now handled by DataSaveSQLite in the constructor
	// public function clearsubmitlimitflag ( )
	// {
	// 	echo (string) $this->db->ClearSubmitlimitFlags();
	// }

}

?>