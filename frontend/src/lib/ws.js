// frontend/src/lib/ws.js
export function createWS(url, { onOpen, onMessage, onClose, onError, maxBackoff=10000 } = {}) {
  let ws=null, retry=0, closed=false;
  const connect=()=>{ ws=new WebSocket(url);
    ws.onopen=()=>{ retry=0; onOpen?.(); };
    ws.onmessage=e=>{ try{ onMessage?.(JSON.parse(e.data)); }catch{ onMessage?.(e.data); } };
    ws.onclose=()=>{ onClose?.(); if(!closed){ setTimeout(connect, Math.min(1000*2**retry++, maxBackoff)); } };
    ws.onerror=e=>onError?.(e);
  };
  connect();
  return {
    send:d=>ws?.readyState===1 && ws.send(typeof d==='string'? d: JSON.stringify(d)),
    close:()=>{ closed=true; ws?.close(); },
    get state(){ return ws?.readyState; }
  };
}
