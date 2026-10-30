// Serves an Amp orb's portal port the way production nginx serves the app (see Dockerfile):
// /reverb/* goes to Reverb with the prefix stripped, every other request to `php artisan serve`.
// A portal cannot reach a second portal hostname, so the chat websocket must share this origin.

import http from 'node:http';
import net from 'node:net';

const appPort = Number(process.env.APP_PORT);
const reverbPort = Number(process.env.REVERB_PORT);

const upstreamFor = (url) =>
    url.startsWith('/reverb/')
        ? { port: reverbPort, path: url.slice('/reverb'.length) }
        : { port: appPort, path: url };

const server = http.createServer((request, response) => {
    const { port, path } = upstreamFor(request.url);

    const upstream = http.request(
        { host: '127.0.0.1', port, path, method: request.method, headers: request.headers },
        (upstreamResponse) => {
            response.writeHead(upstreamResponse.statusCode, upstreamResponse.headers);
            upstreamResponse.pipe(response);
        },
    );

    upstream.on('error', () => {
        if (!response.headersSent) {
            response.writeHead(502, { 'content-type': 'text/plain' });
        }
        response.end(`Nothing is listening on port ${port} yet.\n`);
    });

    request.pipe(upstream);
});

server.on('upgrade', (request, socket, head) => {
    const { port, path } = upstreamFor(request.url);
    const upstream = net.connect(port, '127.0.0.1');

    upstream.on('connect', () => {
        const headerLines = [];
        for (let index = 0; index < request.rawHeaders.length; index += 2) {
            headerLines.push(`${request.rawHeaders[index]}: ${request.rawHeaders[index + 1]}`);
        }

        upstream.write(`${request.method} ${path} HTTP/1.1\r\n${headerLines.join('\r\n')}\r\n\r\n`);
        upstream.write(head);
        upstream.pipe(socket).pipe(upstream);
    });

    upstream.on('error', () => socket.destroy());
    socket.on('error', () => upstream.destroy());
});

server.listen(Number(process.env.PORT), '0.0.0.0');
