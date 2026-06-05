<ul class="fotobox-albums">
{foreach from=$category_thumbnails item=cat name=cat_loop}
  <li class="fotobox-album">
    <a href="{$cat.URL}" class="fotobox-album__link">
      {if $cat.HAS_PASSWORD}<span class="gallery-icon-lock" title="{'This album is password protected'|@translate}"> </span>{/if}
      <span class="fotobox-album__name">{$cat.NAME}</span>
      {if !empty($cat.CAPTION_NB_IMAGES)}<span class="fotobox-album__count">{$cat.CAPTION_NB_IMAGES}</span>{/if}
    </a>
  </li>
{/foreach}
</ul>
