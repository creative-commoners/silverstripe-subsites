const connectedPorts = [];
const messageRelay = (event)=>connectedPorts.forEach(port=>port.postMessage(event.data));
onconnect = function(event) {
    const port = event.ports[0];
    connectedPorts.push(port);
    port.addEventListener('message', messageRelay);
    port.start(); // Required when using addEventListener. Otherwise called implicitly by onmessage setter.
    console.log('connected: ' + connectedPorts.length)
}
