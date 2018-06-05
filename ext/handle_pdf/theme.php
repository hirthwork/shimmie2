<?php

class PdfFileHandlerTheme extends Themelet {
	public function display_image(Page $page, Image $image) {
		$ilink = $image->get_image_link();
		$thumb_url = make_http($image->get_thumb_link()); //used as fallback image
		$html = "<a href='$ilink'><img src='$thumb_url' /></a>";
		$page->add_block(new Block("PDF", $html, "main", 10));
	}
}

