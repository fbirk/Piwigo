<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Lato:wght@300;400;700;900&display=swap" rel="stylesheet">
{if $load_css}
	<!--[if lt IE 7]>
		<link rel="stylesheet" type="text/css" href="{$ROOT_URL}themes/default/fix-ie5-ie6.css">
	<![endif]-->
	<!--[if IE 7]>
		<link rel="stylesheet" type="text/css" href="{$ROOT_URL}themes/default/fix-ie7.css">
	<![endif]-->
	{combine_css path="themes/default/print.css" order=-10}
{/if}
