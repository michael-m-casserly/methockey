var is_ie/*@cc_on = {
  version : parseFloat(navigator.appVersion.match(/MSIE (.+?);/)[1])
}@*/;

if (is_ie && (is_ie.version < 7)) {
  document.write('<scr' + 'ipt id="__ie_ondomload" defer="true" src="//:"></script>');
  
  var el = document.getElementById("__ie_ondomload");
    el.onreadystatechange = function() {
      if ("complete" == this.readyState) {
        this.parentNode.removeChild(this);
        imitateParagraphSiblingCssRule();
      }
    };
    el=null;
}
else {
    document.write("<style>p { margin-top: 0; margin-bottom: 0; } p + p { margin-top: 1em; }</style>");
}

function imitateParagraphSiblingCssRule () {
  var ps = document.getElementsByTagName("p");
  for (i=0; i < ps.length ; ++i) {
    var p = ps[i];
    p.style.marginBottom="0";
    if (p.previousSibling && ("P" == p.previousSibling.tagName)) {
      p.style.marginTop="1em";
    }
    else {
      p.style.marginTop="0";   
    }
  }
}
