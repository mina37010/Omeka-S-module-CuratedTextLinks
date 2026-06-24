(function () {
  function closestDescription(node) {
    if (!node) return null;
    if (node.nodeType === Node.TEXT_NODE) node = node.parentElement;
    return node ? node.closest('.curated-text-description') : null;
  }

  function textOffset(container, node, offset) {
    var walker = document.createTreeWalker(container, NodeFilter.SHOW_TEXT);
    var count = 0;
    while (walker.nextNode()) {
      var current = walker.currentNode;
      if (current === node) return count + offset;
      count += current.nodeValue.length;
    }
    return count;
  }

  function selectedData(container, range) {
    var start = textOffset(container, range.startContainer, range.startOffset);
    var end = textOffset(container, range.endContainer, range.endOffset);
    if (end < start) {
      var swap = start;
      start = end;
      end = swap;
    }
    var source = container.textContent;
    return {
      exact_text: source.slice(start, end),
      start_offset: start,
      end_offset: end,
      prefix_text: source.slice(Math.max(0, start - 30), start),
      suffix_text: source.slice(end, end + 30),
      item_id: Number(container.dataset.ctlItemId),
      property_id: Number(container.dataset.ctlPropertyId),
      value_id: container.dataset.ctlValueId ? Number(container.dataset.ctlValueId) : null
    };
  }

  document.addEventListener('DOMContentLoaded', function () {
    var linkChoiceMenu = null;

    function closeLinkChoiceMenu() {
      if (linkChoiceMenu && linkChoiceMenu.parentNode) {
        linkChoiceMenu.parentNode.removeChild(linkChoiceMenu);
      }
      linkChoiceMenu = null;
    }

    function openLinkChoiceMenu(choice) {
      closeLinkChoiceMenu();
      var sourceMenu = choice.querySelector('.curated-text-link-choice-menu');
      if (!sourceMenu) return;
      linkChoiceMenu = sourceMenu.cloneNode(true);
      linkChoiceMenu.classList.add('is-open');
      document.body.appendChild(linkChoiceMenu);
      var rect = choice.getBoundingClientRect();
      var width = Math.min(320, window.innerWidth - 24);
      var left = Math.min(Math.max(12, rect.left), window.innerWidth - width - 12);
      var top = rect.bottom + 6;
      var menuHeight = linkChoiceMenu.offsetHeight || 160;
      if (top + menuHeight > window.innerHeight - 12) {
        top = Math.max(12, rect.top - menuHeight - 6);
      }
      linkChoiceMenu.style.left = left + 'px';
      linkChoiceMenu.style.top = top + 'px';
      linkChoiceMenu.style.width = width + 'px';
    }

    document.addEventListener('click', function (event) {
      var choice = event.target.closest ? event.target.closest('.curated-text-link-choice') : null;
      if (choice) {
        event.preventDefault();
        openLinkChoiceMenu(choice);
        return;
      }
      if (linkChoiceMenu && linkChoiceMenu.contains(event.target)) {
        return;
      }
      closeLinkChoiceMenu();
    });

    document.addEventListener('keydown', function (event) {
      if (event.key === 'Escape') {
        closeLinkChoiceMenu();
      }
      if ((event.key === 'Enter' || event.key === ' ') && event.target && event.target.classList && event.target.classList.contains('curated-text-link-choice')) {
        event.preventDefault();
        openLinkChoiceMenu(event.target);
      }
    });

    window.addEventListener('scroll', closeLinkChoiceMenu, true);
    window.addEventListener('resize', closeLinkChoiceMenu);

    function networkColor(group) {
      return {
        item: '#4b6f9f',
        person: '#8a5a9e',
        event: '#b4564f',
        work: '#527a45',
        place: '#2f7f8f',
        organization: '#9a6a2f',
        concept: '#666666'
      }[group] || '#666666';
    }

    function renderNetwork(block) {
      var canvas = block.querySelector('[data-ctl-network-canvas]');
      var detail = block.querySelector('[data-ctl-network-detail]');
      var controls = block.querySelectorAll('[data-ctl-network-zoom]');
      var showButton = block.querySelector('[data-ctl-network-show]');
      var itemLabelToggle = block.querySelector('[data-ctl-network-toggle-item-labels]');
      var summary = block.querySelector('[data-ctl-network-summary]');
      if (!canvas) return;
      var graph = {};
      var networkUrl = block.dataset.ctlNetworkUrl || '';
      try {
        graph = JSON.parse(block.dataset.ctlNetwork || '{}');
      } catch (e) {
        graph = {};
      }
      var networkLoaded = !networkUrl;
      var rendered = false;
      function drawNetwork() {
      if (rendered) return;
      if (networkUrl && !networkLoaded) {
        if (summary) summary.textContent = 'Loading network...';
        if (showButton) showButton.disabled = true;
        fetch(networkUrl, {
          headers: {'X-Requested-With': 'XMLHttpRequest'},
          credentials: 'same-origin'
        }).then(function (response) {
          if (!response.ok) throw new Error('Network load failed');
          return response.json();
        }).then(function (data) {
          graph = data || {};
          networkLoaded = true;
          if (showButton) showButton.disabled = false;
          drawNetwork();
        }).catch(function () {
          if (showButton) showButton.disabled = false;
          if (summary) summary.textContent = 'Network load failed.';
        });
        return;
      }
      rendered = true;
      var nodes = graph.nodes || [];
      var edges = graph.edges || [];
      if (!nodes.length) {
        canvas.hidden = false;
        canvas.textContent = 'No curated links to display.';
        if (summary) summary.textContent = '0 nodes / 0 links';
        return;
      }
      if (summary) {
        summary.textContent = nodes.length + ' nodes / ' + edges.length + ' links';
      }
      canvas.hidden = false;
      if (showButton) showButton.hidden = true;
      var nodeCount = nodes.length;
      var baseWidth = canvas.clientWidth || 960;
      var width = Math.max(900, Math.min(9000, Math.round(baseWidth + nodeCount * 18)));
      var height = Math.max(560, Math.min(7000, Math.round(420 + nodeCount * 12)));
      var nodeState = {};
      nodes.forEach(function (node, index) {
        var angle = (Math.PI * 2 * index) / Math.max(1, nodes.length);
        var radiusBase = Math.min(width, height) * (node.kind === 'item' ? 0.42 : 0.26);
        var radius = radiusBase * (0.8 + (index % 5) * 0.08);
        nodeState[node.id] = {
          node: node,
          x: width / 2 + Math.cos(angle) * radius,
          y: height / 2 + Math.sin(angle) * radius,
          vx: 0,
          vy: 0,
          r: node.kind === 'item' ? 9 : 18,
          fixed: false
        };
      });

      function runLayout(iterations) {
        for (var step = 0; step < iterations; step++) {
          nodes.forEach(function (aNode, i) {
            var a = nodeState[aNode.id];
            for (var j = i + 1; j < nodes.length; j++) {
              var b = nodeState[nodes[j].id];
              var dx = b.x - a.x;
              var dy = b.y - a.y;
              var distanceSq = Math.max(80, dx * dx + dy * dy);
              var distance = Math.sqrt(distanceSq);
              var force = 2600 / distanceSq;
              var fx = (dx / distance) * force;
              var fy = (dy / distance) * force;
              if (!a.fixed) {
                a.vx -= fx;
                a.vy -= fy;
              }
              if (!b.fixed) {
                b.vx += fx;
                b.vy += fy;
              }
            }
          });
          edges.forEach(function (edge) {
            var a = nodeState[edge.source];
            var b = nodeState[edge.target];
            if (!a || !b) return;
            var dx = b.x - a.x;
            var dy = b.y - a.y;
            var distance = Math.max(1, Math.sqrt(dx * dx + dy * dy));
            var desired = a.node.kind === b.node.kind ? 190 : 145;
            var force = (distance - desired) * 0.018;
            var fx = (dx / distance) * force;
            var fy = (dy / distance) * force;
            if (!a.fixed) {
              a.vx += fx;
              a.vy += fy;
            }
            if (!b.fixed) {
              b.vx -= fx;
              b.vy -= fy;
            }
          });
          nodes.forEach(function (node) {
            var s = nodeState[node.id];
            if (!s.fixed) {
              s.vx += (width / 2 - s.x) * 0.004;
              s.vy += (height / 2 - s.y) * 0.004;
              s.vx *= 0.82;
              s.vy *= 0.82;
              s.x = Math.max(44, Math.min(width - 44, s.x + s.vx));
              s.y = Math.max(44, Math.min(height - 44, s.y + s.vy));
            }
          });
        }
      }
      runLayout(Math.max(120, Math.min(360, 90 + Math.round(nodeCount * 0.8))));

      var svg = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
      svg.setAttribute('viewBox', '0 0 ' + width + ' ' + height);
      svg.setAttribute('role', 'img');
      svg.classList.add('curated-text-network-svg');
      var defs = document.createElementNS('http://www.w3.org/2000/svg', 'defs');
      svg.appendChild(defs);
      var viewport = document.createElementNS('http://www.w3.org/2000/svg', 'g');
      svg.appendChild(viewport);
      function graphBounds() {
        var bounds = {minX: Infinity, minY: Infinity, maxX: -Infinity, maxY: -Infinity};
        nodes.forEach(function (node) {
          var s = nodeState[node.id];
          if (!s) return;
          bounds.minX = Math.min(bounds.minX, s.x - s.r - 80);
          bounds.minY = Math.min(bounds.minY, s.y - s.r - 40);
          bounds.maxX = Math.max(bounds.maxX, s.x + s.r + 80);
          bounds.maxY = Math.max(bounds.maxY, s.y + s.r + 40);
        });
        if (!isFinite(bounds.minX)) {
          bounds = {minX: 0, minY: 0, maxX: width, maxY: height};
        }
        return bounds;
      }
      function fitTransform() {
        var bounds = graphBounds();
        var viewWidth = width;
        var viewHeight = height;
        var graphWidth = Math.max(1, bounds.maxX - bounds.minX);
        var graphHeight = Math.max(1, bounds.maxY - bounds.minY);
        var scale = Math.min(viewWidth / graphWidth, viewHeight / graphHeight) * 0.96;
        scale = Math.max(0.08, Math.min(4, scale));
        var centerX = (bounds.minX + bounds.maxX) / 2;
        var centerY = (bounds.minY + bounds.maxY) / 2;
        return {
          x: viewWidth / 2 - centerX * scale,
          y: viewHeight / 2 - centerY * scale,
          scale: scale
        };
      }
      var initialTransform = fitTransform();
      var transform = {x: initialTransform.x, y: initialTransform.y, scale: initialTransform.scale};
      var applyTransform = function () {
        viewport.setAttribute('transform', 'translate(' + transform.x + ' ' + transform.y + ') scale(' + transform.scale + ')');
      };
      function pointerSvgPoint(clientX, clientY) {
        var rect = svg.getBoundingClientRect();
        return {
          x: (clientX - rect.left) * width / Math.max(1, rect.width),
          y: (clientY - rect.top) * height / Math.max(1, rect.height)
        };
      }
      function zoomAt(nextScale, clientX, clientY) {
        nextScale = Math.max(0.08, Math.min(30, nextScale));
        var point = pointerSvgPoint(clientX, clientY);
        var worldX = (point.x - transform.x) / transform.scale;
        var worldY = (point.y - transform.y) / transform.scale;
        transform.x = point.x - worldX * nextScale;
        transform.y = point.y - worldY * nextScale;
        transform.scale = nextScale;
        applyTransform();
      }
      var zoom = function (direction) {
        if (direction === 'reset') {
          initialTransform = fitTransform();
          transform = {x: initialTransform.x, y: initialTransform.y, scale: initialTransform.scale};
          applyTransform();
        } else {
          var rect = svg.getBoundingClientRect();
          zoomAt(transform.scale * (direction === 'in' ? 1.35 : 0.74), rect.left + rect.width / 2, rect.top + rect.height / 2);
        }
      };

      var edgeElements = [];
      var nodeElements = {};
      var panMoved = false;
      var pointerDownNodeId = null;
      var itemLabelsVisible = true;
      function updateGraph() {
        edgeElements.forEach(function (entry) {
          var a = nodeState[entry.edge.source];
          var b = nodeState[entry.edge.target];
          if (!a || !b) return;
          entry.line.setAttribute('x1', a.x);
          entry.line.setAttribute('y1', a.y);
          entry.line.setAttribute('x2', b.x);
          entry.line.setAttribute('y2', b.y);
        });
        Object.keys(nodeElements).forEach(function (id) {
          var s = nodeState[id];
          nodeElements[id].setAttribute('transform', 'translate(' + s.x + ' ' + s.y + ')');
        });
      }

      function selectNetworkNode(group, node) {
        Array.prototype.forEach.call(svg.querySelectorAll('.curated-text-network-node'), function (nodeElement) {
          nodeElement.classList.remove('is-selected');
        });
        group.classList.add('is-selected');
        showNetworkDetail(detail, node, edges, nodes);
      }

      edges.forEach(function (edge) {
        var a = nodeState[edge.source];
        var b = nodeState[edge.target];
        if (!a || !b) return;
        var line = document.createElementNS('http://www.w3.org/2000/svg', 'line');
        line.setAttribute('class', 'curated-text-network-edge');
        viewport.appendChild(line);
        edgeElements.push({line: line, edge: edge});
      });

      nodes.forEach(function (node) {
        var state = nodeState[node.id];
        if (!state) return;
        var group = document.createElementNS('http://www.w3.org/2000/svg', 'g');
        group.setAttribute('class', 'curated-text-network-node');
        group.classList.add('curated-text-network-node-' + node.kind);
        if (node.current) {
          group.classList.add('curated-text-network-node-current');
        }
        group.setAttribute('tabindex', '0');
        group.setAttribute('data-node-id', node.id);
        var circle = document.createElementNS('http://www.w3.org/2000/svg', 'circle');
        circle.setAttribute('cx', 0);
        circle.setAttribute('cy', 0);
        circle.setAttribute('r', state.r);
        circle.setAttribute('fill', networkColor(node.group));
        var text = document.createElementNS('http://www.w3.org/2000/svg', 'text');
        if (node.kind === 'item') {
          text.classList.add('curated-text-network-item-label');
        }
        text.setAttribute('x', 0);
        text.setAttribute('y', state.r + (node.kind === 'item' ? 13 : 15));
        text.setAttribute('text-anchor', 'middle');
        text.textContent = node.label.length > 18 ? node.label.slice(0, 17) + '...' : node.label;
        group.appendChild(circle);
        group.appendChild(text);
        group.addEventListener('click', function (event) {
          if (panMoved) {
            event.preventDefault();
            panMoved = false;
            return;
          }
          event.stopPropagation();
          selectNetworkNode(group, node);
        });
        group.addEventListener('keydown', function (event) {
          if (event.key === 'Enter' || event.key === ' ') {
            event.preventDefault();
            showNetworkDetail(detail, node, edges, nodes);
          }
        });
        viewport.appendChild(group);
        nodeElements[node.id] = group;
      });
      canvas.innerHTML = '';
      canvas.appendChild(svg);
      updateGraph();
      applyTransform();
      Array.prototype.forEach.call(controls, function (button) {
        button.addEventListener('click', function () {
          zoom(button.dataset.ctlNetworkZoom);
        });
      });
      if (itemLabelToggle) {
        itemLabelToggle.addEventListener('click', function () {
          itemLabelsVisible = !itemLabelsVisible;
          block.classList.toggle('curated-text-network-hide-item-labels', !itemLabelsVisible);
          itemLabelToggle.textContent = itemLabelsVisible ? 'Hide item names' : 'Show item names';
        });
      }
      var dragging = false;
      var last = null;
      var dragStart = null;
      svg.addEventListener('wheel', function (event) {
        event.preventDefault();
        var factor = event.deltaY < 0 ? 1.2 : 0.83;
        zoomAt(transform.scale * factor, event.clientX, event.clientY);
      }, {passive: false});
      svg.addEventListener('pointerdown', function (event) {
        dragging = true;
        panMoved = false;
        var downNode = event.target.closest ? event.target.closest('.curated-text-network-node') : null;
        pointerDownNodeId = downNode ? downNode.getAttribute('data-node-id') : null;
        last = {x: event.clientX, y: event.clientY};
        dragStart = {x: event.clientX, y: event.clientY};
        svg.setPointerCapture(event.pointerId);
      });
      svg.addEventListener('pointermove', function (event) {
        if (!dragging || !last) return;
        var rect = svg.getBoundingClientRect();
        var dx = (event.clientX - last.x) * width / Math.max(1, rect.width);
        var dy = (event.clientY - last.y) * height / Math.max(1, rect.height);
        if (dragStart && Math.abs(event.clientX - dragStart.x) + Math.abs(event.clientY - dragStart.y) > 4) {
          panMoved = true;
        }
        transform.x += dx;
        transform.y += dy;
        last = {x: event.clientX, y: event.clientY};
        applyTransform();
      });
      svg.addEventListener('pointerup', function () {
        if (!panMoved && pointerDownNodeId && nodeElements[pointerDownNodeId]) {
          var selectedNode = (graph.nodes || []).find(function (node) {
            return node.id === pointerDownNodeId;
          });
          if (selectedNode) {
            selectNetworkNode(nodeElements[pointerDownNodeId], selectedNode);
          }
        }
        dragging = false;
        last = null;
        dragStart = null;
        pointerDownNodeId = null;
      });
      svg.addEventListener('pointercancel', function () {
        dragging = false;
        last = null;
        dragStart = null;
        pointerDownNodeId = null;
      });
      }
      if (showButton) {
        showButton.addEventListener('click', drawNetwork);
      }
      if (block.dataset.ctlNetworkAuto === '1') {
        drawNetwork();
      } else {
        if (!showButton) {
          drawNetwork();
        }
      }
    }

    function showNetworkDetail(detail, node, edges, nodes) {
      if (!detail) return;
      var byId = {};
      nodes.forEach(function (item) { byId[item.id] = item; });
      var related = edges.filter(function (edge) {
        return edge.source === node.id || edge.target === node.id;
      });
      detail.innerHTML = '';
      if (node.thumbnail) {
        var thumb = document.createElement('img');
        thumb.className = 'curated-text-network-detail-thumb';
        thumb.src = node.thumbnail;
        thumb.alt = '';
        detail.appendChild(thumb);
      }
      var title = document.createElement('strong');
      title.textContent = node.label;
      detail.appendChild(title);
      var meta = document.createElement('div');
      meta.className = 'curated-text-network-detail-meta';
      meta.textContent = node.group + ' / ' + node.id;
      detail.appendChild(meta);
      if (node.url) {
        var nodeLink = document.createElement('a');
        nodeLink.className = 'curated-text-network-detail-link';
        nodeLink.href = node.url;
        nodeLink.textContent = node.kind === 'item' ? 'Open item page' : 'Open link';
        detail.appendChild(nodeLink);
      }
      if (node.targets && node.targets.length) {
        node.targets.forEach(function (target) {
          var link = document.createElement(target.url ? 'a' : 'div');
          link.className = 'curated-text-network-detail-link';
          if (target.url) link.href = target.url;
          link.textContent = target.label + ' / ' + target.source;
          detail.appendChild(link);
        });
      }
      related.forEach(function (edge) {
        var other = byId[edge.source === node.id ? edge.target : edge.source];
        if (node.kind === 'link' && other && other.kind === 'item') return;
        var link = document.createElement(edge.url ? 'a' : 'div');
        link.className = 'curated-text-network-detail-link';
        if (edge.url) link.href = edge.url;
        link.textContent = (other ? other.label : edge.label) + ' / ' + edge.label;
        detail.appendChild(link);
      });
    }

    Array.prototype.forEach.call(document.querySelectorAll('.curated-text-network'), renderNetwork);

    function detectReadingCardCorner(card) {
      var src = card.dataset.ctlThumbnail;
      if (!src) return;
      var image = new Image();
      image.crossOrigin = 'anonymous';
      image.onload = function () {
        try {
          var canvas = document.createElement('canvas');
          var size = 96;
          canvas.width = size;
          canvas.height = size;
          var context = canvas.getContext('2d', {willReadFrequently: true});
          if (!context) return;
          context.drawImage(image, 0, 0, size, size);
          var data = context.getImageData(0, 0, size, size).data;
          var quadrants = [
            {name: 'top-left', x0: 0, y0: 0, x1: size / 2, y1: size / 2, score: 0},
            {name: 'top-right', x0: size / 2, y0: 0, x1: size, y1: size / 2, score: 0},
            {name: 'bottom-left', x0: 0, y0: size / 2, x1: size / 2, y1: size, score: 0},
            {name: 'bottom-right', x0: size / 2, y0: size / 2, x1: size, y1: size, score: 0}
          ];
          quadrants.forEach(function (quadrant) {
            var score = 0;
            for (var y = quadrant.y0; y < quadrant.y1; y += 2) {
              for (var x = quadrant.x0; x < quadrant.x1; x += 2) {
                var index = (y * size + x) * 4;
                var r = data[index];
                var g = data[index + 1];
                var b = data[index + 2];
                var max = Math.max(r, g, b);
                var min = Math.min(r, g, b);
                var luma = 0.2126 * r + 0.7152 * g + 0.0722 * b;
                var saturation = max ? (max - min) / max : 0;
                var ink = Math.max(0, 242 - luma);
                var warm = r > b + 10 && g > b + 3 && r > 105 && g > 90 && luma > 105 && luma < 245;
                var paleOrange = warm ? Math.max(0, (r + g) / 2 - b) : 0;
                score += warm ? 120 + paleOrange * 3 + ink * (1 - saturation * 0.35) : ink * 0.18;
              }
            }
            quadrant.score = score;
          });
          quadrants.sort(function (a, b) { return b.score - a.score; });
          ['top-left', 'top-right', 'bottom-left', 'bottom-right'].forEach(function (corner) {
            card.classList.remove('curated-text-reading-card-' + corner);
          });
          card.classList.add('curated-text-reading-card-' + quadrants[0].name);
        } catch (e) {
          return;
        }
      };
      image.src = src;
    }

    Array.prototype.forEach.call(document.querySelectorAll('[data-ctl-reading-card]'), detectReadingCardCorner);

    var titleRenderer = document.querySelector('[data-ctl-title-html]');
    if (titleRenderer && titleRenderer.dataset.ctlTitleHtml) {
      var pageTitle = document.querySelector('body.item.resource.show h2 .title');
      if (pageTitle) {
        pageTitle.innerHTML = titleRenderer.dataset.ctlTitleHtml;
      }
    }
    var popover = document.getElementById('curated-text-links-popover');
    if (!popover) return;
    var form = popover.querySelector('form');
    var selectedText = popover.querySelector('[data-ctl-selected-text]');
    var selectedCandidate = popover.querySelector('[data-ctl-selected-candidate]');
    var candidatesBody = popover.querySelector('[data-ctl-candidates-body]');
    var searchQuery = popover.querySelector('[data-ctl-search-query]');
    var linkLabel = popover.querySelector('[data-ctl-link-label]');
    var linkType = popover.querySelector('[data-ctl-link-type]');
    var targetUriVisible = popover.querySelector('[data-ctl-target-uri-visible]');
    var targetResourceVisible = popover.querySelector('[data-ctl-target-resource-visible]');
    var active = null;
    var activeMark = null;
    var selectedRemoteTargets = [];
    var selectedLocalTargets = [];

    function setCandidatesState(message) {
      if (candidatesBody) candidatesBody.textContent = message;
    }

    function queryText() {
      var query = searchQuery ? searchQuery.value.trim() : '';
      return query || (active ? active.exact_text.trim() : '');
    }

    function targetKey(candidate) {
      return [
        candidate.target_uri || '',
        candidate.target_resource_id || '',
        candidate.label || candidate.target_label || '',
          candidate.local_match ? [
            candidate.local_match.item_id || '',
            candidate.local_match.property_id || '',
            candidate.local_match.value_id || '',
            candidate.local_match.start_offset || '',
            candidate.local_match.end_offset || ''
        ].join(':') : '',
        candidate.local_item_id || ''
      ].join('|');
    }

    function isLocalCandidate(candidate) {
      return !!candidate.local_match || !!candidate.local_item_id;
    }

    function candidateTarget(candidate) {
      var isLocalMatch = isLocalCandidate(candidate);
      return {
        label: candidate.label || candidate.target_label || (active ? active.exact_text.trim() : ''),
        target_label: candidate.label || candidate.target_label || (active ? active.exact_text.trim() : ''),
        target_type: candidate.target_type || 'concept',
        target_uri: isLocalMatch ? '' : (candidate.target_uri || ''),
        target_resource_id: isLocalMatch ? '' : (candidate.target_resource_id || ''),
        local_item_id: candidate.local_item_id || (candidate.local_match ? candidate.local_match.item_id : null),
        local_match: candidate.local_match || null
      };
    }

    function refreshSelectedTargets() {
      if (!selectedCandidate) return;
      if (!selectedRemoteTargets.length && !selectedLocalTargets.length) {
        selectedCandidate.hidden = true;
        selectedCandidate.textContent = '';
        return;
      }
      var parts = [];
      if (selectedRemoteTargets.length) {
        parts.push('Targets: ' + selectedRemoteTargets.map(function (target) {
          return target.target_label || target.label || target.target_uri || ('Item #' + target.target_resource_id);
        }).join(', '));
      }
      if (selectedLocalTargets.length) {
        parts.push('Local items: ' + selectedLocalTargets.map(function (target) {
          var match = target.local_match || {};
          return (target.target_label || target.label || ('Item #' + target.local_item_id)) +
            (match.property_label ? ' / ' + match.property_label : '') +
            (match.start_offset !== undefined ? ' / ' + match.start_offset + '-' + match.end_offset : '');
        }).join(', '));
      }
      selectedCandidate.hidden = false;
      selectedCandidate.textContent = parts.join(' | ');
    }

    function selectCandidate(candidate) {
      var target = candidateTarget(candidate);
      var selectedList = isLocalCandidate(candidate) ? selectedLocalTargets : selectedRemoteTargets;
      if (!isLocalCandidate(candidate)) {
        form.target_type.value = candidate.target_type || 'concept';
        form.target_uri.value = candidate.target_uri || '';
        form.target_resource_id.value = candidate.target_resource_id || '';
        form.target_label.value = target.target_label;
        if (linkType && linkType.value === 'concept') linkType.value = candidate.target_type || 'concept';
        if (targetUriVisible) targetUriVisible.value = form.target_uri.value;
        if (targetResourceVisible) targetResourceVisible.value = form.target_resource_id.value;
      }
      if (!selectedList.some(function (selected) { return targetKey(selected) === targetKey(target); })) {
        selectedList.push(target);
      }
      refreshSelectedTargets();
    }

    function toggleCandidate(candidate, button) {
      var target = candidateTarget(candidate);
      var key = targetKey(target);
      var selectedList = isLocalCandidate(candidate) ? selectedLocalTargets : selectedRemoteTargets;
      var existingIndex = selectedList.findIndex(function (selected) {
        return targetKey(selected) === key;
      });
      if (existingIndex === -1) {
        selectCandidate(candidate);
        button.classList.add('is-selected');
      } else {
        selectedList.splice(existingIndex, 1);
        button.classList.remove('is-selected');
        refreshSelectedTargets();
      }
    }

    function renderCandidates(candidates) {
      candidatesBody.innerHTML = '';
      if (!candidates.length) {
        setCandidatesState('No candidates found. Enter URI or Item ID manually.');
        return;
      }
      var bulkActions = document.createElement('div');
      bulkActions.className = 'curated-text-links-candidate-actions';
      var selectAll = document.createElement('button');
      selectAll.type = 'button';
      selectAll.textContent = 'Select all';
      var clearVisible = document.createElement('button');
      clearVisible.type = 'button';
      clearVisible.textContent = 'Clear shown';
      bulkActions.appendChild(selectAll);
      bulkActions.appendChild(clearVisible);
      candidatesBody.appendChild(bulkActions);
      selectAll.addEventListener('click', function () {
        candidates.forEach(function (candidate) {
          selectCandidate(candidate);
        });
        renderCandidates(candidates);
      });
      clearVisible.addEventListener('click', function () {
        candidates.forEach(function (candidate) {
          var target = candidateTarget(candidate);
          var key = targetKey(target);
          var selectedList = isLocalCandidate(candidate) ? selectedLocalTargets : selectedRemoteTargets;
          var existingIndex = selectedList.findIndex(function (selected) {
            return targetKey(selected) === key;
          });
          if (existingIndex !== -1) {
            selectedList.splice(existingIndex, 1);
          }
        });
        refreshSelectedTargets();
        renderCandidates(candidates);
      });
      candidates.forEach(function (candidate) {
        var button = document.createElement('button');
        button.type = 'button';
        button.className = 'curated-text-links-candidate';
        button.dataset.targetType = candidate.target_type || 'concept';
        button.innerHTML = '<span class="curated-text-links-candidate-label"></span><span class="curated-text-links-candidate-meta"></span>';
        button.querySelector('.curated-text-links-candidate-label').textContent = candidate.label || 'Untitled';
        button.querySelector('.curated-text-links-candidate-meta').textContent = [
          candidate.target_type || 'concept',
          candidate.source || 'candidate',
          candidate.local_item_id ? ('Local item #' + candidate.local_item_id) : (candidate.local_match ? 'Local match' : ''),
          candidate.note || ''
        ].filter(Boolean).join(' · ');
        button.addEventListener('click', function () {
          toggleCandidate(candidate, button);
        });
        var selectedList = isLocalCandidate(candidate) ? selectedLocalTargets : selectedRemoteTargets;
        if (selectedList.some(function (selected) { return targetKey(selected) === targetKey(candidateTarget(candidate)); })) {
          button.classList.add('is-selected');
        }
        candidatesBody.appendChild(button);
      });
    }

    function fetchCandidates(text) {
      text = (text || queryText()).trim();
      if (!text) return;
      var url = new URL(popover.dataset.candidatesUrl, window.location.href);
      url.searchParams.set('q', text);
      url.searchParams.set('item_id', popover.dataset.itemId || '');
      setCandidatesState('Searching candidates...');
      fetch(url.toString(), {
        headers: {'X-Requested-With': 'XMLHttpRequest'},
        credentials: 'same-origin'
      }).then(function (response) {
        if (!response.ok) throw new Error('Candidate search failed');
        return response.json();
      }).then(function (data) {
        renderCandidates(data.candidates || []);
      }).catch(function () {
        setCandidatesState('Candidate search failed. Enter URI or Item ID manually.');
      });
    }

    function fetchAuthority(source) {
      if (!active) return;
      var text = queryText();
      if (!text) return;
      var url = new URL(popover.dataset.authorityUrl, window.location.href);
      url.searchParams.set('source', source);
      url.searchParams.set('q', text);
      url.searchParams.set('refresh', '1');
      setCandidatesState('Searching ' + (source === 'ndl' ? 'NDL' : 'Wikidata') + '...');
      fetch(url.toString(), {
        headers: {'X-Requested-With': 'XMLHttpRequest'},
        credentials: 'same-origin'
      }).then(function (response) {
        if (!response.ok) throw new Error('Authority search failed');
        return response.json();
      }).then(function (data) {
        renderCandidates(data.candidates || []);
      }).catch(function () {
        setCandidatesState('Authority search failed.');
      });
    }

    function clearActiveMark() {
      if (!activeMark || !activeMark.parentNode) {
        activeMark = null;
        return;
      }
      var parent = activeMark.parentNode;
      while (activeMark.firstChild) {
        parent.insertBefore(activeMark.firstChild, activeMark);
      }
      parent.removeChild(activeMark);
      parent.normalize();
      activeMark = null;
    }

    function markRange(range) {
      clearActiveMark();
      try {
        var mark = document.createElement('mark');
        mark.className = 'curated-text-links-active-selection';
        range.cloneRange().surroundContents(mark);
        activeMark = mark;
      } catch (e) {
        activeMark = null;
      }
    }

    document.addEventListener('mouseup', function () {
      var selection = window.getSelection();
      if (!selection || selection.rangeCount === 0 || selection.isCollapsed) return;
      var range = selection.getRangeAt(0);
      var container = closestDescription(range.commonAncestorContainer);
      if (!container) return;
      active = selectedData(container, range);
      if (!active.exact_text.trim()) return;
      form.target_label.value = active.exact_text.trim();
      selectedText.textContent = active.exact_text.trim();
      if (searchQuery) searchQuery.value = active.exact_text.trim();
      if (linkLabel) linkLabel.value = active.exact_text.trim();
      if (linkType) linkType.value = 'concept';
      selectedRemoteTargets = [];
      selectedLocalTargets = [];
      form.target_uri.value = '';
      form.target_resource_id.value = '';
      if (targetUriVisible) targetUriVisible.value = '';
      if (targetResourceVisible) targetResourceVisible.value = '';
      if (selectedCandidate) {
        selectedCandidate.hidden = true;
        selectedCandidate.textContent = '';
      }
      markRange(range);
      fetchCandidates(queryText());
      var rect = range.getBoundingClientRect();
      popover.hidden = false;
      popover.style.left = Math.max(12, rect.left + window.scrollX) + 'px';
      popover.style.top = Math.max(12, rect.bottom + window.scrollY + 8) + 'px';
    });

    popover.querySelector('[data-ctl-cancel]').addEventListener('click', function () {
      popover.hidden = true;
      active = null;
      clearActiveMark();
      window.getSelection().removeAllRanges();
    });

    Array.prototype.forEach.call(popover.querySelectorAll('[data-ctl-authority]'), function (button) {
      button.addEventListener('click', function () {
        fetchAuthority(button.dataset.ctlAuthority);
      });
    });

    var localSearch = popover.querySelector('[data-ctl-local-search]');
    if (localSearch) {
      localSearch.addEventListener('click', function () {
        fetchCandidates(queryText());
      });
    }

    if (searchQuery) {
      searchQuery.addEventListener('keydown', function (event) {
        if (event.key === 'Enter') {
          event.preventDefault();
          fetchCandidates(queryText());
        }
      });
    }

    if (targetUriVisible) {
      targetUriVisible.addEventListener('input', function () {
        form.target_uri.value = targetUriVisible.value.trim();
      });
    }
    if (targetResourceVisible) {
      targetResourceVisible.addEventListener('input', function () {
        form.target_resource_id.value = targetResourceVisible.value.trim();
      });
    }

    form.addEventListener('submit', function (event) {
      event.preventDefault();
      if (!active) return;
      var createEvent = form.event_title.value.trim() !== '';
      var manualTarget = {
        target_type: form.target_type.value,
        target_uri: form.target_uri.value.trim(),
        target_resource_id: form.target_resource_id.value.trim(),
        target_label: form.target_label.value.trim() || active.exact_text
      };
      var selectedTargets = selectedRemoteTargets.concat(selectedLocalTargets);
      var payload = Object.assign({}, active, {
        target_type: manualTarget.target_type,
        target_uri: manualTarget.target_uri,
        target_resource_id: manualTarget.target_resource_id,
        target_label: manualTarget.target_label,
        search_query: searchQuery ? searchQuery.value.trim() : '',
        link_label: linkLabel ? (linkLabel.value.trim() || active.exact_text) : active.exact_text,
        link_type: linkType ? linkType.value : manualTarget.target_type,
        targets: selectedTargets.length ? selectedTargets : null,
        target_property_term: form.target_property_term.value,
        create_event_item: createEvent,
        event: {
          title: form.event_title.value.trim() || active.exact_text,
          alt_label: form.event_alt_label.value.trim(),
          start_date: form.event_start_date.value.trim(),
          end_date: form.event_end_date.value.trim(),
          location: form.event_location.value.trim(),
          description: form.event_description.value.trim(),
          source: form.event_source.value.trim(),
          same_as: form.event_same_as.value.trim()
        }
      });
      fetch(popover.dataset.saveUrl, {
        method: 'POST',
        headers: {'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest'},
        credentials: 'same-origin',
        body: JSON.stringify(payload)
      }).then(function (response) {
        return response.json().catch(function () {
          return {};
        }).then(function (data) {
          if (!response.ok) {
            throw new Error(data.error || 'Save failed');
          }
          return data;
        });
      }).then(function () {
        popover.hidden = true;
        window.location.reload();
      }).catch(function (error) {
        alert(error.message);
      });
    });
  });
})();
