/* Navigation Javascript Starts */
function imageSwap(img, src) { 
  var objStr,obj; 
  if(document.images){ 
    if (typeof(img) == 'string') { 
      objStr = 'document.' + img; 
      obj = eval(objStr); 
      obj.src = src; 
    } else if ((typeof(img) == 'object') && img && img.src) { 
      img.src = src; 
    } 
  } 
} 
/* Navigation Javascript Ends */
