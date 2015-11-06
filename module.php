<?php
// Classes and libraries for module system
//
// webtrees: Web based Family History software
// Copyright (C) 2015 Łukasz Wileński.
//
// This program is free software; you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation; either version 2 of the License, or
// (at your option) any later version.
//
// This program is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with this program; if not, write to the Free Software
// Foundation, Inc., 59 Temple Place, Suite 330, Boston, MA  02111-1307  USA
//
namespace Wooc\WebtreesAddon\WoocAlbumModule;
use Fisharebest\Webtrees\Auth;
use Fisharebest\Webtrees\Database;
use Fisharebest\Webtrees\Filter;
use Fisharebest\Webtrees\Functions\FunctionsPrint;
use Fisharebest\Webtrees\I18N;
use Fisharebest\Webtrees\Individual;
use Fisharebest\Webtrees\Media;
use Fisharebest\Webtrees\Menu;
use Fisharebest\Webtrees\Module;
use Fisharebest\Webtrees\Module\AbstractModule;
use Fisharebest\Webtrees\Module\ModuleTabInterface;
use PDO;

class WoocAlbumModule extends AbstractModule implements ModuleTabInterface {
	private $media_list;

	public function __construct() {
		parent::__construct('wooc_album');
	}

	// Extend Module
	public function getTitle() {
		return /* I18N: Name of a module */ I18N::translate('Wooc Album');
	}

	public function getTabTitle() {
		return /* I18N: Title used in the tab panel */ I18N::translate('Album');
	}

	// Extend Module
	public function getDescription() {
		return /* I18N: Description of the “Album” module */ I18N::translate('Tab that showing all the images linked to the individual.');
	}

	// Implement Module_Tab
	public function defaultTabOrder() {
		return 60;
	}

	// Implement Module_Tab
	public function hasTabContent() {
		global $WT_TREE;
		return Auth::isEditor($WT_TREE) || $this->get_media();
	}

	// Implement Module_Tab
	public function isGrayedOut() {
		return !$this->get_media();
	}

	// Implement Module_Tab
	public function getTabContent() {
		global $WT_TREE, $controller;

		$html=	'<link rel="stylesheet" href="'.WT_MODULES_DIR.$this->getName().'/css/style.css" type="text/css">';
		$html.='<div id="'.$this->getName().'_content">';
		//Show Album header Links
		if (Auth::isEditor($WT_TREE)) {
			$html.='<div class="descriptionbox rela">';
			// Add a new media object
			if ($WT_TREE->getPreference('MEDIA_UPLOAD') >= Auth::accessLevel($WT_TREE)) {
				$html.='<span><a href="#" onclick="window.open(\'addmedia.php?action=showmediaform&linktoid='.$controller->record->getXref().'\', \'_blank\', \'resizable=1,scrollbars=1,top=50,height=780,width=600\');return false;">';
				$html.='<img src="'.WT_STATIC_URL.WT_MODULES_DIR.$this->getName().'/images/image_add.png" id="head_icon" class="icon" title="'.I18N::translate('Add a new media object').'" alt="'.I18N::translate('Add a new media object').'">';
				$html.=I18N::translate('Add a new media object');
				$html.='</a></span>';
				// Link to an existing item
				$html.='<span><a href="#" onclick="window.open(\'inverselink.php?linktoid='.$controller->record->getXref().'&linkto=person\', \'_blank\', \'resizable=1,scrollbars=1,top=50,height=300,width=450\');">';
				$html.= '<img src="'.WT_STATIC_URL.WT_MODULES_DIR.$this->getName().'/images/image_link.png" id="head_icon" class="icon" title="'.I18N::translate('Link to an existing media object').'" alt="'.I18N::translate('Link to an existing media object').'">';
				$html.=I18N::translate('Link to an existing media object');
				$html.='</a></span>';
			}
			if (Auth::isModerator($WT_TREE) && $this->get_media()) {
				// Popup Reorder Media
				$html.='<span><a href="#" onclick="reorder_media(\''.$controller->record->getXref().'\')">';
				$html.='<img src="'.WT_STATIC_URL.WT_MODULES_DIR.$this->getName().'/images/images.png" id="head_icon" class="icon" title="'.I18N::translate('Re-order media').'" alt="'.I18N::translate('Re-order media').'">';
				$html.=I18N::translate('Re-order media');
				$html.='</a></span>';
				$html.='</td>';
			}
			$html.='</div>';
		}
		$media_found = false;

		// Used when sorting media on album tab page
		$html.='<div width="100%" cellpadding="0" border="0">';
		ob_start();
		$this->album_print_media($controller->record->getXref(), 0, true, 0); // ALL
		return
			$html.
			ob_get_clean().
			'</div></div>';
	}

	// Implement Module_Tab
	public function canLoadAjax() {
		global $SEARCH_SPIDER;

		return !$SEARCH_SPIDER; // Search engines cannot use AJAX
	}

	// Implement Module_Tab
	public function getPreLoadContent() {
		global $controller;

		$controller->addInlineJavascript('jQuery("a[href$=' . $this->getName() . ']").text("' . $this->getTabTitle() . '");');
	}

	
	// Get all facts containing media links for this person and their spouse-family records
	private function get_media() {
		global $WT_TREE, $controller;

		if ($this->media_list === null) {
			// Use facts from this individual and all their spouses
			$facts = $controller->record->getFacts();
			foreach ($controller->record->getSpouseFamilies() as $family) {
				foreach ($family->getFacts() as $fact) {
					$facts[] = $fact;
				}
			}
			// Use all media from each fact
			$this->media_list = array();
			foreach ($facts as $fact) {
				if (!$fact->isPendingDeletion()) { // Don't show pending edits, as the user just sees duplicates
					preg_match_all('/(?:^1|\n\d) OBJE @(' . WT_REGEX_XREF . ')@/', $fact->getGedcom(), $matches);
					foreach ($matches[1] as $match) {
						$media = Media::getInstance($match, $WT_TREE);
						if ($media && $media->canShow()) {
							$this->media_list[] = $media;
						}
					}
				}
			}
			// If a media object is linked twice, only show it once
			$this->media_list = array_unique($this->media_list);
			// Sort these using _WT_OBJE_SORT
			$wt_obje_sort = array();
			foreach ($controller->record->getFacts('_WT_OBJE_SORT') as $fact) {
				$wt_obje_sort[] = trim($fact->getValue(), '@');
			}
			usort($this->media_list, function($x, $y) use ($wt_obje_sort) {
				return array_search($x->getXref(), $wt_obje_sort) - array_search($y->getXref(), $wt_obje_sort);
			});
		}
		return $this->media_list;
	}

	static public function getMediaListMenu($mediaobject) {
		$html='<div id="album-menu"><ul class="makeMenu lb-menu">';
		$menu = new Menu(I18N::translate('Edit Details'), '#', 'lb-image_edit');
		$menu->setOnclick("return window.open('addmedia.php?action=editmedia&amp;pid=".$mediaobject->getXref()."', '_blank', edit_window_specs);");
		$html.=$menu->getMenuAsList().'</ul><ul class="makeMenu lb-menu">';
		$menu = new Menu(I18N::translate('Set link'), '#', 'lb-image_link');
		$menu->setOnclick("return ilinkitem('".$mediaobject->getXref()."','person')");
		$submenu = new Menu(I18N::translate('To Person'), '#');
		$submenu->setOnclick("return ilinkitem('".$mediaobject->getXref()."','person')");
		$menu->addSubMenu($submenu);
		$submenu = new Menu(I18N::translate('To Family'), '#');
		$submenu->setOnclick("return ilinkitem('".$mediaobject->getXref()."','family')");
		$menu->addSubMenu($submenu);
		$submenu = new Menu(I18N::translate('To Source'), '#');
		$submenu->setOnclick("return ilinkitem('".$mediaobject->getXref()."','source')");
		$menu->addSubMenu($submenu);
		$html.=$menu->getMenuAsList().'</ul><ul class="makeMenu lb-menu">';
		$menu = new Menu(I18N::translate('View Details'), $mediaobject->getHtmlUrl(), 'lb-image_view');
		$html.=$menu->getMenuAsList();
		$html.='</ul></div>';
		return $html;
	}
	
	private function album_print_media($pid, $level=1, $related=false, $noedit=false) {
		global $WT_TREE;
		global $res, $rowm;

		$person = Individual::getInstance($pid, $WT_TREE);

		//-- find all of the related ids
		$ids = array($person->getXref());
		if ($related) {
			foreach ($person->getSpouseFamilies() as $family) {
				$ids[] = $family->getXref();
			}
		}

		//-- If they exist, get a list of the sorted current objects in the indi gedcom record  -  (1 _WT_OBJE_SORT @xxx@ .... etc) ----------
		$sort_current_objes = array();
		$sort_ct = preg_match_all('/\n1 _WT_OBJE_SORT @(.*)@/', $person->getGedcom(), $sort_match, PREG_SET_ORDER);
		for ($i=0; $i<$sort_ct; $i++) {
			if (!isset($sort_current_objes[$sort_match[$i][1]])) {
				$sort_current_objes[$sort_match[$i][1]] = 1;
			} else {
				$sort_current_objes[$sort_match[$i][1]]++;
			}
			$sort_obje_links[$sort_match[$i][1]][] = $sort_match[$i][0];
		}

		// create ORDER BY list from Gedcom sorted records list
		$orderbylist = 'ORDER BY '; // initialize
		foreach ($sort_match as $id) {
			$orderbylist .= "m_id='$id[1]' DESC, ";
		}
		$orderbylist = rtrim($orderbylist, ', ');

		//-- get a list of the current objects in the record
		$current_objes = array();
		if ($level>0) {
			$regexp = '/\n' . $level . ' OBJE @(.*)@/';
		} else {
			$regexp = '/\n\d OBJE @(.*)@/';
		}
		$ct = preg_match_all($regexp, $person->getGedcom(), $match, PREG_SET_ORDER);
		for ($i=0; $i<$ct; $i++) {
			if (!isset($current_objes[$match[$i][1]])) {
				$current_objes[$match[$i][1]] = 1;
			} else {
				$current_objes[$match[$i][1]]++;
			}
			$obje_links[$match[$i][1]][] = $match[$i][0];
		}

		$media_found = false;

		// Get the related media items
		$sqlmm =
			"SELECT DISTINCT m_id, m_ext, m_filename, m_titl, m_file, m_gedcom, l_from AS pid" .
			" FROM `##media`" .
			" JOIN `##link` ON (m_id=l_to AND m_file=l_file AND l_type='OBJE')" .
			" WHERE m_file=? AND l_from IN (";
		$i=0;
		$vars=array($WT_TREE->getTreeId());
		foreach ($ids as $media_id) {
			if ($i>0) $sqlmm .= ", ";
			$sqlmm .= "?";
			$vars[]=$media_id;
			$i++;
		}
		$sqlmm .= ')';


		if ($sort_ct>0) {
			$sqlmm .= $orderbylist;
		}

		$pics=Database::prepare($sqlmm)->execute($vars)->fetchAll(PDO::FETCH_ASSOC);

		$foundObjs = array();
		$numm = count($pics);

		// Begin to Layout the Album Media Rows
		if ($numm>0) {
				echo '<div id="thumbcontainer">';//STYLE

			// Start pulling media items into thumbcontainer div
			foreach ($pics as $rowm) {
				if (isset($foundObjs[$rowm['m_id']])) {
					if (isset($current_objes[$rowm['m_id']])) {
						$current_objes[$rowm['m_id']]--;
					}
					continue;
				}
				$pics=array();

				//-- if there is a change to this media item then get the
				//-- updated media item and show it
				//if (($newrec=find_updated_record($rowm['m_id'], $ged_id)) ) {
				//	$row = array();
				//	$row['m_id'] = $rowm['m_id'];
				//	$row['m_file'] = $ged_id;
				//	$row['m_filename'] = get_gedcom_value('FILE', 1, $newrec);
				//	$row['m_titl'] = get_gedcom_value('TITL', 1, $newrec);
				//	if (empty($row['m_titl'])) $row['m_titl'] = get_gedcom_value('FILE:TITL', 1, $newrec);
				//	$row['m_gedcom'] = $newrec;
				//	$et = preg_match('/\.(\w+)$/', $row['m_filename'], $ematch);
				//	$ext = '';
				//	if ($et>0) $ext = $ematch[1];
				//	$row['m_ext'] = $ext;
				//	$row['pid'] = $pid;
				//	$pics['new'] = $row;
				//	$pics['old'] = $rowm;
				//} else {
					if (!isset($current_objes[$rowm['m_id']]) && ($rowm['pid']==$pid)) {
						$pics['old'] = $rowm;
					} else {
						$pics['normal'] = $rowm;
						if (isset($current_objes[$rowm['m_id']])) {
							$current_objes[$rowm['m_id']]--;
						}
					}
				//}
				foreach ($pics as $rtype => $rowm) {
						$res = $this->album_print_media_row($rtype, $rowm, $pid);
					$media_found = $media_found || $res;
					$foundObjs[$rowm['m_id']]=true;
				}
			}

				echo '</div>';
				echo '<div class="clearlist">';
				echo '</div>';
		}
		if ($media_found) return $is_media='YES';
		else return $is_media='NO';
	}

	/**
	 * print a media row
	 * @param string $rtype whether this is a 'new', 'old', or 'normal' media row... this is used to determine if the rows should be printed with an outline color
	 * @param array $rowm        An array with the details about this media item
	 * @param string $pid        The record id this media item was attached to
	 */
	private function album_print_media_row($rtype, $rowm, $pid) {
		global $controller, $notes, $WT_TREE;

		$media=Media::getInstance($rowm['m_id'], $WT_TREE);

		if ($media && !$media->canShow()) {
			// This media object is private;
			return false;
		}

		// Highlight Album Thumbnails - Changed=new (blue), Changed=old (red), Changed=no (none)
		 if ($rtype=='new') {
			echo '<div class="album_new"><div class="pic">';
		} else if ($rtype=='old') {
			echo '<div class="album_old"><div class="pic">';
		} else {
			echo '<div class="album_norm"><div class="pic">';
		}

		//  Get the title of the media
		if ($media) {
			$mediaTitle = $media->getFullName();
		} else {
			$mediaTitle = $rowm['m_id'];
		}

		//Get media item Notes
		$haystack = $rowm['m_gedcom'];
		$needle   = '1 NOTE';
		$before   = substr($haystack, 0, strpos($haystack, $needle));
		$after    = substr(strstr($haystack, $needle), strlen($needle));
		$final    = $before.$needle.$after;
		$notes    = htmlspecialchars(addslashes(FunctionsPrint::printFactNotes($final, 1, true, true)), ENT_QUOTES);

		// Prepare Below Thumbnail menu
		$mtitle = '<div class="album_media_title">' . $mediaTitle . '</div>';
		$menu = new Menu($mtitle);

		if ($rtype=='old') {
			// Do not print menu if item has changed and this is the old item
		} else {
			// Continue printing menu
			$menu->addClass('', 'submenu');

			// View Notes
			if (strpos($media->getGedcom(), "\n1 NOTE")) {
				$submenu = new Menu(I18N::translate('View notes'), '#', '', array(
					'onclick' => 'modalNotes("' . Filter::escapeJs($notes) . '", "' . I18N::translate('View notes') . '"); return false;',
				));
				$submenu->addClass("submenuitem");
				$menu->addSubMenu($submenu);
			}
			//View Details
			$submenu = new Menu(I18N::translate('View details'), WT_BASE_URL . "mediaviewer.php?mid=".$rowm['m_id'].'&amp;ged='.$WT_TREE->getNameUrl(), 'right');
			$submenu->addClass("submenuitem");
			$menu->addSubMenu($submenu);
			//View Sources
			$source_menu = null;
			foreach ($media->getFacts('SOUR') as $source_fact) {
				$source = $source_fact->getTarget();
				if ($source && $source->canShow()) {
					if (!$source_menu) {
						// Group sources under a top level menu
						$source_menu = new Menu(I18N::translate('Sources'), '#');
						$source_menu->addClass('submenuitem', 'submenu');
					}
					//now add a link to the actual source as a submenu
					$submenu = new Menu(new Menu(strip_tags($source->getFullName()), $source->getHtmlUrl()));
					$submenu->addClass('submenuitem');
					$source_menu->addSubMenu($submenu);
				}
			}
			if ($source_menu) {
				$menu->addSubMenu($source_menu);
			}
			if (Auth::isEditor($WT_TREE)) {
				// Edit Media
				$submenu = new Menu(I18N::translate('Edit media'), '#', '', array(
					'onclick' => 'return window.open("addmedia.php?action=editmedia&pid=' . $rowm['m_id'] . '", "_blank", edit_window_specs);',
				));
				$submenu->addClass("submenuitem");
				$menu->addSubMenu($submenu);
				if (Auth::isManager($WT_TREE)) {
					// Manage Links
					if (Module::getModuleByName('GEDFact_assistant')) {
						$submenu = new Menu(I18N::translate('Manage links'), '#', '', array(
							'onclick' => 'return window.open("inverselink.php?mediaid=' . $rowm['m_id'] . '&amp;linkto=manage", "_blank", find_window_specs);',
						));
						$submenu->addClass("submenuitem");
						$menu->addSubMenu($submenu);
					} else {
						$submenu = new Menu(I18N::translate('Set link'), '#', null, 'right', 'right');
						$submenu->addClass('submenuitem', 'submenu');

						$ssubmenu = new Menu(I18N::translate('To Person'), '#', '', array(
							'onclick' => 'return window.open("inverselink.php?mediaid=' . $rowm['m_id'] . '&amp;linkto=person", "_blank", find_window_specs);',
						));
						$ssubmenu->addClass('submenuitem', 'submenu');
						$submenu->addSubMenu($ssubmenu);

						$ssubmenu = new Menu(I18N::translate('To Family'), '#', '', array(
							'onclick' => 'return window.open("inverselink.php?mediaid=' . $rowm['m_id'] . '&amp;linkto=family", "_blank", find_window_specs);',
						));
						$ssubmenu->addClass('submenuitem', 'submenu');
						$submenu->addSubMenu($ssubmenu);

						$ssubmenu = new Menu(I18N::translate('To Source'), '#', '', array(
							'onclick' => 'return window.open("inverselink.php?mediaid=' . $rowm['m_id'] . '&amp;linkto=source", "_blank", find_window_specs);',
						));
						$ssubmenu->addClass('submenuitem', 'submenu');
						$submenu->addSubMenu($ssubmenu);

						$menu->addSubMenu($submenu);
					}
					// Unlink Media
					$submenu = new Menu(I18N::translate('Unlink Media'), '#', '', array(
							'onclick' => 'return unlink_media("' . I18N::translate('Are you sure you want to remove links to this media object?') . '", "' . $controller->record->getXref() . '", "' . $media->getXref() . '");',
						));
					$submenu->addClass("submenuitem");
					$menu->addSubMenu($submenu);
				}
			}
		}

		// Print Thumbnail
		if ($media) {echo $media->displayImage();}
		echo '</div>';

		//View Edit Menu
		echo '<div>', $menu->getMenu(), '</div>';
		echo '</div>';

		return true;
	}
}

return new WoocAlbumModule;