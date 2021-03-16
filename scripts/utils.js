function wsp_printCoupon(elemId) {
  var iframeId = "iframe_" + elemId;
  
  function closeIFrame() {
    var coupon = document.getElementById(iframeId);
    if (coupon) {
      coupon.parentNode.removeChild(coupon);
    }
  }
  
  function setupContent(id, coupon) {
    var inst, doc, win;
    if (navigator.appName == "Microsoft Internet Explorer") {
      inst = window.frames[id];
      doc = inst.window.document;
      win = inst.window;
    }
    else {
      inst = document.getElementById(id);
      doc = inst.contentDocument;
      win = inst.contentWindow;
    }
    
    var head = doc.getElementsByTagName('head').item(0);
    // Wait for it to load
    if (!head || !doc.body || doc.title != "coupon_html") {
      doc.open("text/html");
      doc.write('<!DOCTYPE HTML PUBLIC "-//W3C//DTD HTML 4.01 Transitional//EN" "http://www.w3.org/TR/html4/loose.dtd"><html>');
      doc.writeln("<head xmlns=\"http://www.w3.org/1999/xhtml\">");
      doc.writeln("<title>coupon_html</title>");
      doc.writeln('<meta http-equiv="Content-Type" content="text/html; charset=UTF-8">');
      doc.writeln("<style>");
      doc.writeln("@media print { #table_border { border: 1px #000000 dashed; } }");
      doc.writeln("</style>");
      doc.writeln("</head>");
      doc.writeln("<body>");
      doc.writeln(coupon.innerHTML);
      doc.writeln("</body>");
      doc.writeln("</html>");
      doc.close();
  
      window.setTimeout(function(){setupContent(id);}, 100);
      return;
    }
    win.focus();

    if (win.attachEvent) {
      if (!win.attachEvent("onafterprint", closeIFrame)) {
        setTimeout(closeIFrame, 5000);
      }
      window.print();
    }
    else {
      win.print();
      setTimeout(closeIFrame, 5000);
    }
  }
  
  var coupon = document.getElementById(elemId);
  var iframe = document.createElement("iframe");
  iframe.setAttribute("id", iframeId);
  iframe.setAttribute("border", "0");
  iframe.setAttribute("frameBorder", "0");
  iframe.setAttribute("marginWidth", "0");
  iframe.setAttribute("marginHeight", "0");
  iframe.setAttribute("leftMargin", "0");
  iframe.setAttribute("topMargin", "0");
  iframe.setAttribute("scrolling", "no");
  iframe.setAttribute("width", 100);
  iframe.setAttribute("height", 100);
  document.body.appendChild(iframe);
  iframe.setAttribute("src", wsp_htmlref_blank);
  setupContent(iframeId, coupon);
}
