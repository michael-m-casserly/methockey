// Place any sitewide Javascript in here, it is referenced from the banner-head.html include file

function handleSelectMenuItem(elm) {
    window.location = elm.value;
    elm.value = ''; // Reset drop-down menu after selection
  }

function handleSelectExternalLink(elm)
  {
     window.open(elm.value, '_blank');
     elm.value = ''; // Reset drop-down menu after selection
  }
