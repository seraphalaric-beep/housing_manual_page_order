(function () {
	'use strict';

	var form = document.querySelector('.hmpo_form');

	if (!form) {
		return;
	}

	var tree = form.querySelector('.hmpo_tree');
	var status = form.querySelector('.hmpo_status');
	var draggedItem = null;
	var draggedList = null;
	var lists = Array.prototype.slice.call(form.querySelectorAll('.hmpo_list'));

	function directItems(list) {
		return Array.prototype.filter.call(list.children, function (child) {
			return child.classList.contains('hmpo_item');
		});
	}

	function currentOrder(list) {
		return directItems(list).map(function (item) {
			return item.getAttribute('data-page-id');
		}).join(',');
	}

	function listHasChanged(list) {
		return list.getAttribute('data-original-order') !== currentOrder(list);
	}

	function anyListHasChanged() {
		return lists.some(listHasChanged);
	}

	function refreshList(list) {
		var items = directItems(list);
		var changed = listHasChanged(list);

		items.forEach(function (item, index) {
			var orderLabel = item.querySelector('.hmpo_position');
			var upButton = item.querySelector('.hmpo_move_up');
			var downButton = item.querySelector('.hmpo_move_down');

			if (orderLabel) {
				if (changed) {
					orderLabel.textContent = 'New order: ' + index;
				} else {
					orderLabel.textContent = 'Order: ' + item.getAttribute('data-menu-order');
				}
			}

			if (upButton) {
				upButton.disabled = index === 0;
			}

			if (downButton) {
				downButton.disabled = index === items.length - 1;
			}
		});
	}

	function refreshAll() {
		lists.forEach(refreshList);

		if (status) {
			status.textContent = anyListHasChanged()
				? 'There are unsaved order changes. Select Save order to apply them.'
				: 'No unsaved changes.';
		}
	}

	function moveItem(item, direction) {
		var list = item.parentElement;
		var sibling = 'up' === direction ? item.previousElementSibling : item.nextElementSibling;

		if (!list || !sibling || !sibling.classList.contains('hmpo_item')) {
			return;
		}

		if ('up' === direction) {
			list.insertBefore(item, sibling);
		} else {
			list.insertBefore(sibling, item);
		}

		refreshAll();
	}

	lists.forEach(function (list) {
		list.setAttribute('data-original-order', currentOrder(list));

		list.addEventListener('dragover', function (event) {
			if (!draggedItem || draggedList !== list) {
				return;
			}

			var target = event.target instanceof Element ? event.target.closest('.hmpo_item') : null;

			if (!target || target.parentElement !== list || target === draggedItem) {
				return;
			}

			event.preventDefault();

			var bounds = target.getBoundingClientRect();
			var placeAfter = event.clientY > bounds.top + bounds.height / 2;

			if (placeAfter && draggedItem !== target.nextElementSibling) {
				list.insertBefore(draggedItem, target.nextElementSibling);
				refreshAll();
			} else if (!placeAfter && draggedItem !== target.previousElementSibling) {
				list.insertBefore(draggedItem, target);
				refreshAll();
			}
		});
	});

	tree.addEventListener('dragstart', function (event) {
		var target = event.target instanceof Element ? event.target : null;
		var handle = target ? target.closest('.hmpo_handle') : null;
		var item = target ? target.closest('.hmpo_item') : null;

		if (!handle || !item || !item.parentElement || !item.parentElement.classList.contains('hmpo_list')) {
			event.preventDefault();
			return;
		}

		draggedItem = item;
		draggedList = item.parentElement;
		item.classList.add('hmpo_dragging');

		if (event.dataTransfer) {
			event.dataTransfer.effectAllowed = 'move';
			event.dataTransfer.setData('text/plain', item.getAttribute('data-page-id'));
		}
	});

	tree.addEventListener('dragend', function () {
		if (draggedItem) {
			draggedItem.classList.remove('hmpo_dragging');
		}

		draggedItem = null;
		draggedList = null;
		refreshAll();
	});

	tree.addEventListener('click', function (event) {
		var target = event.target instanceof Element ? event.target : null;
		var upButton = target ? target.closest('.hmpo_move_up') : null;
		var downButton = target ? target.closest('.hmpo_move_down') : null;
		var button = upButton || downButton;

		if (!button) {
			return;
		}

		var item = button.closest('.hmpo_item');

		if (item) {
			moveItem(item, upButton ? 'up' : 'down');
		}
	});

	refreshAll();
}());
