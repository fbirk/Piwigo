{if isset($MENUBAR)}{$MENUBAR}{/if}
<div id="content" class="content{if isset($MENUBAR)} contentWithMenu{/if}">

<div class="titrePage">
	<ul class="categoryActions">
	</ul>
	<h2><a href="{$U_HOME}">{'Home'|@translate}</a>{$LEVEL_SEPARATOR}{'Album password'|@translate}</h2>
</div>

{include file='infos_errors.tpl'}

<form action="{$F_ACTION}" method="post" name="album_password_form" class="properties">
  <fieldset>
    <legend>{$ALBUM_NAME} - {'This album is password protected'|@translate}</legend>

    <ul>
      <li>
        <span class="property">
          <label for="album_password">{'Enter the password to access this album'|@translate}</label>
        </span>
        <input tabindex="1" class="login" type="password" name="album_password" id="album_password" size="25" autofocus>
      </li>
    </ul>
  </fieldset>

  <p>
    <input type="hidden" name="redirect" value="{$U_REDIRECT|@htmlspecialchars}">
    <input tabindex="2" type="submit" value="{'Submit'|@translate}">
  </p>
</form>

<script type="text/javascript"><!--
document.album_password_form.album_password.focus();
//--></script>

</div> <!-- content -->
