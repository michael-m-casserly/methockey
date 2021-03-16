/** function openpopup 
* @description Open a popup window at specified positions with specified dimensions.
* @param Window hwndPopup is a global paramter that will refer the new popup window instance that
*        will be opened.
*
*   var popupWidth    = 300;
*   var popupHeight   = 300;
*   var popupTop      = 300;
*   var popupLeft     = 300;
*   var isFullScreen  = false;
*   var isAutoCenter  = false;
*   var popupTarget   = "popupwin";
*   var popupParams   = "toolbar=0, scrollbars=0, menubar=0, status=0, resizable=1";
*/

function openpopup(/*Window*/ hwndPopup, /*String*/ url, /*int*/ popupWidth, /*int*/ popupHeight, /*int*/popupTop,
                   /*int*/ popupLeft, /*boolean*/ isFullScreen, /*boolean*/ isAutoCenter,
                   /*String*/ popupTarget, /*String*/ popupParams) {

	if (typeof(popupWidth) == "string") {
		popupWidth = parseInt(window.screen.width * (parseInt(popupWidth)/100));
	}
	if (typeof(popupHeight) == "string") {
		popupHeight = parseInt(window.screen.height * (parseInt(popupHeight)/100));
	}
	if (typeof(popupLeft) == "string") {
		popupLeft = parseInt(window.screen.width * (parseInt(popupLeft)/100));
	}
	if (typeof(popupTop) == "string") {
		popupTop = parseInt(window.screen.height * (parseInt(popupTop)/100));
	}
  

  if (isFullScreen) {
		popupParams += ", fullscreen=1";
		popupTop = 0; popupLeft = 0;
		popupHeight = window.screen.height;
		popupWidth = window.screen.width;
  } else if (isAutoCenter) {
    popupTop  = parseInt((window.screen.height - popupHeight)/2);
    popupLeft = parseInt((window.screen.width - popupWidth)/2);
  }

  var ua = window.navigator.userAgent;
  var isOpera = (ua.indexOf("Opera") > -1);
  var operaVersion;
  var isMac = (ua.indexOf("Mac") > -1);

  if (isMac && url.indexOf("http") != 0) {
    url = location.href.substring(0,location.href.lastIndexOf('\/')) + "/" + url;
  }

  if (isOpera) {
    var i = ua.indexOf("Opera");
    operaVersion = parseFloat(ua.substring(i + 6, ua.indexOf(" ", i + 8)));
    if (operaVersion > 7.00) {
      var isAccessible = false;
      eval("try { isAccessible = ( (hwndPopup != null) && !hwndPopup.closed ); } catch(exc) { } ");
      if (!isAccessible) {
        hwndPopup = null;
      }
    }
  }

  if ( (hwndPopup == null) || hwndPopup.closed ) {    
    if (isOpera && (operaVersion < 7)) {
      if (url.indexOf("http") != 0) {			 
        hwndPopup = window.open(url,popupTarget,popupParams + ((!isFullScreen && (popupWidth > 0 && popupHeight > 0)) ? ", width=" + popupWidth +", height=" + popupHeight : ""));
        if (!isFullScreen) {
          hwndPopup.moveTo(popupLeft, popupTop);
        }
        hwndPopup.focus();
        return;
      }
    }
    if (!(window.navigator.appName == "Netscape" && !document.getElementById)) {
      //not ns4
      popupParams += ((popupWidth > 0 && popupHeight > 0) ? (", width=" + popupWidth +", height=" + popupHeight) : "") + ", left=" + popupLeft + ", top=" + popupTop;
    } else {
      popupParams += ", left=" + popupLeft + ", top=" + popupTop;
    }
    //alert(popupParams);
    hwndPopup = window.open("",popupTarget,popupParams);
    if (!isFullScreen) {
      if (popupWidth > 0 && popupHeight > 0)
			hwndPopup.resizeTo(popupWidth, popupHeight);
      hwndPopup.moveTo(popupLeft, popupTop);
    }
    hwndPopup.focus();
    with (hwndPopup.document) {
      open();
      write("<ht"+"ml><he"+"ad></he"+"ad><bo"+"dy onLoad=\"window.location.href='" + url + "'\"></bo"+"dy></ht"+"ml>");
      close();
    }
  } else {
    if (isOpera && (operaVersion > 7.00)) {
      eval("try { hwndPopup.focus(); hwndPopup.location.href = url; } catch(exc) { hwndPopup = window.open(\""+ url +"\",\"" + popupTarget +"\",\""+ popupParams +
			((popupWidth > 0 && popupHeight > 0) ? (", width=" + popupWidth +", height=" + popupHeight) : "") +
			"\"); } ");
    } else {
      hwndPopup.focus();
      hwndPopup.location.href = url;
    } 
  }
}// end function

