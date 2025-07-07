<?php   
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

include "Fabian.config.php";

function normalize(string $s): string {
    return ucfirst(strtolower(trim($s)));
}

static $recipesByResult = null;
static $emojiMap = null;
static $discovered = null;

function loadData(PDO $conn) {
    global $recipesByResult, $emojiMap, $discovered;
    if ($recipesByResult !== null) return;

    $recipesByResult = [];
    $emojiMap = [];
    $discovered = [];

    $stmt = $conn->query("SELECT id, item1, item2, result, emoji FROM INFCRA_recipes2");
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $a = normalize($row['item1']);
        $b = normalize($row['item2']);
        $res = normalize($row['result']);
        $emoji = $row['emoji'] ?? '';

        $recipesByResult[$res][] = [
            'id' => $row['id'],
            'item1' => $a,
            'item2' => $b,
        ];

        if (!isset($emojiMap[$res])) {
            $emojiMap[$res] = $emoji;
        }
        if (!isset($emojiMap[$a])) {
            $emojiMap[$a] = '';
        }
        if (!isset($emojiMap[$b])) {
            $emojiMap[$b] = '';
        }
    }

    $base = ['Fire', 'Earth', 'Water', 'Wind'];
    foreach ($base as $b) {
        $discovered[$b] = ['depth' => 0, 'from' => null];
        if (!isset($emojiMap[$b])) {
            $emojiMap[$b] = '';
        }
        if (!isset($recipesByResult[$b])) {
            $recipesByResult[$b] = [];
        }
    }


    $queue = $base;
    while (!empty($queue)) {
        $current = array_shift($queue);
        foreach ($recipesByResult as $result => $recipes) {
            foreach ($recipes as $recipe) {
                if ($recipe['item1'] === $current || $recipe['item2'] === $current) {
                    if (isset($discovered[$recipe['item1']]) && isset($discovered[$recipe['item2']])) {
                        $depth = max($discovered[$recipe['item1']]['depth'], $discovered[$recipe['item2']]['depth']) + 1;
                        if (!isset($discovered[$result]) || $depth < $discovered[$result]['depth']) {
                            $discovered[$result] = ['depth' => $depth, 'from' => [$recipe['item1'], $recipe['item2']]];
                            $queue[] = $result;
                        }
                    }
                }
            }
        }
    }
}

loadData($conn);

function renderDropdown(array $emojiMap, array $discovered, ?string $selected = null): string {
    $options = [];
    foreach ($emojiMap as $item => $emoji) {
        $depth = $discovered[$item]['depth'] ?? 0;
        $display = trim(($emoji ? $emoji . ' ' : '') . $item . " (depth: $depth)");
        $sel = ($item === $selected) ? ' selected' : '';
        $options[] = "<option value=\"" . htmlspecialchars($item) . "\"$sel>" . htmlspecialchars($display) . "</option>";
    }
    return "<select id='itemSelect'>" . implode("\n", $options) . "</select>";
}

function renderCraftTree(string $item, array $recipesByResult, array $discovered, array $emojiMap, array $visited = []): string {
    if (in_array($item, $visited, true)) {
        return "<div style='color: red; font-style: italic;'>Cycle detected: " . htmlspecialchars($item) . "</div>";
    }
    $visited[] = $item;

    $depth = $discovered[$item]['depth'] ?? 0;
    $depthLabel = "<small style='color: #888; margin-left: 8px;'>(depth: $depth)</small>";

    $emoji = $emojiMap[$item] ?? '';
    $emojiPart = $emoji ? " $emoji" : "";

    $checked = ($depth === 0) ? ' checked disabled' : '';

    if (!isset($discovered[$item]) || $discovered[$item]['from'] === null) {
        return "<div class='item base'><input type='checkbox'$checked>" . $emojiPart . htmlspecialchars($item) . " $depthLabel</div>";
    }

    $recipes = $recipesByResult[$item] ?? [];
    $minDepth = $depth;
    $chosenRecipe = null;
    foreach ($recipes as $recipe) {
        $d1 = $discovered[$recipe['item1']]['depth'] ?? 9999;
        $d2 = $discovered[$recipe['item2']]['depth'] ?? 9999;
        $expectedDepth = max($d1, $d2) + 1;
        if ($expectedDepth === $minDepth) {
            $chosenRecipe = $recipe;
            break;
        }
    }
    if ($chosenRecipe === null) {
        return "<div class='item base'><input type='checkbox'$checked>" . $emojiPart . htmlspecialchars($item) . " $depthLabel</div>";
    }

    $left = renderCraftTree($chosenRecipe['item1'], $recipesByResult, $discovered, $emojiMap, $visited);
    $right = renderCraftTree($chosenRecipe['item2'], $recipesByResult, $discovered, $emojiMap, $visited);

    return "<div class='item'>
                <button class='result' onclick='toggleSubtree(this)'><input type='checkbox'$checked>" . $emojiPart . htmlspecialchars($item) . " $depthLabel</button>
                <div class='subtree' style='margin-top: 6px; margin-left: 20px; border-left: 2px solid #ccc; padding-left: 10px;'>
                    <div class='left'>$left</div>
                    <div class='right'>$right</div>
                </div>
            </div>";
}

if (isset($_GET['ajax']) && $_GET['ajax'] === 'tree' && isset($_GET['item'])) {
    $item = normalize($_GET['item']);
    loadData($conn);
    header('Content-Type: text/html; charset=utf-8');
    echo renderCraftTree($item, $recipesByResult, $discovered, $emojiMap);
    exit;
}

$defaultItem = 'Pokemon';

?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8" />
<title>Crafting Tree with Depth & Dropdown</title>
<style>
    .item { margin-bottom: 10px; }
    .base { font-weight: bold; }
    .result { font-weight: bold; font-size: 1.1em; display: block; margin-bottom: 4px; background: #eef; border: none; cursor: pointer; padding: 4px; width: 100%; text-align: left; }
    select { margin-top: 5px; width: 250px; }
    .subtree { display: block; }
    #suggestions {
        border:1px solid #ccc;
        max-height: 150px;
        overflow-y: auto;
        width: 250px;
        display:none;
        background:#fff;
        position:absolute;
        z-index:1000;
    }
</style>
</head>
<body>

<h1>Crafting Tree</h1>

<input type="text" id="searchInput" placeholder="Search items..." autocomplete="off" style="width: 250px;">
<div id="suggestions"></div>

<label for="itemSelect">Select Item:</label><br>
<?php
echo renderDropdown($emojiMap, $discovered, $defaultItem);
?>

<div id="treeContainer" style="margin-top: 20px; border-top: 1px solid #ccc; padding-top: 10px;">
<?php
echo renderCraftTree($defaultItem, $recipesByResult, $discovered, $emojiMap);
?>
</div>

<script>
const select = document.getElementById('itemSelect');
const searchInput = document.getElementById('searchInput');
const suggestions = document.getElementById('suggestions');
const treeContainer = document.getElementById('treeContainer');

function loadTreeForItem(item) {
    treeContainer.innerHTML = 'Loading...';
    fetch('?ajax=tree&item=' + encodeURIComponent(item))
        .then(res => res.text())
        .then(html => {
            treeContainer.innerHTML = html;
        })
        .catch(err => {
            treeContainer.innerHTML = '<span style="color:red;">Error loading tree</span>';
            console.error(err);
        });
}

function showSuggestions(list) {
    if (list.length === 0) {
        suggestions.style.display = 'none';
        suggestions.innerHTML = '';
        return;
    }

    suggestions.innerHTML = '';
    list.forEach(({item, emoji, depth}) => {
        const div = document.createElement('div');
        div.textContent = (emoji ? emoji + ' ' : '') + item + " (depth: " + depth + ")";
        div.style.padding = '4px';
        div.style.cursor = 'pointer';

        div.addEventListener('click', () => {
            searchInput.value = item;
            suggestions.style.display = 'none';

            // Synchroniseer dropdown selectie
            if (select) {
                select.value = item;
            }

            loadTreeForItem(item);
        });

        suggestions.appendChild(div);
    });
    suggestions.style.display = 'block';
}

select.addEventListener('change', function() {
    const selectedItem = this.value;

    // Synchroniseer zoekveld (optioneel)
    if (searchInput.value !== selectedItem) {
        searchInput.value = selectedItem;
    }

    loadTreeForItem(selectedItem);
});

let debounceTimeout = null;
searchInput.addEventListener('input', () => {
    const val = searchInput.value.trim();
    if (debounceTimeout) clearTimeout(debounceTimeout);

    if (val.length === 0) {
        suggestions.style.display = 'none';
        treeContainer.innerHTML = '';
        return;
    }

    debounceTimeout = setTimeout(() => {
        fetch('search_items.php?q=' + encodeURIComponent(val))
            .then(res => res.json())
            .then(data => {
                // data = [{item: "...", emoji: "...", depth: ...}, ...]
                showSuggestions(data);
            })
            .catch(err => {
                console.error(err);
                suggestions.style.display = 'none';
            });
    }, 250);
});

document.addEventListener('click', (e) => {
    if (!suggestions.contains(e.target) && e.target !== searchInput) {
        suggestions.style.display = 'none';
    }
});

// Toggle subtree visibility when clicking on result button
function toggleSubtree(btn) {
    const subtree = btn.nextElementSibling;
    if (!subtree) return;
    if (subtree.style.display === 'none') {
        subtree.style.display = 'block';
    } else {
        subtree.style.display = 'none';
    }
}
</script>

</body>
</html>
