import { currentUrlParams, reloadWithQuery } from '@/utils/route';
import { router, usePage } from '@inertiajs/react';
import { Button, Chip, Group, Tooltip } from '@mantine/core';
import { useDidUpdate } from '@mantine/hooks';
import { IconDeviceFloppy } from '@tabler/icons-react';
import { useState } from 'react';

const sameSet = (a, b) => {
  if (a.length !== b.length) return false;
  const sortedB = [...b].sort();
  return [...a].sort().every((value, index) => value === sortedB[index]);
};

export default function ProjectTagFilter({ tags }) {
  const { auth } = usePage().props;

  const availableIds = new Set((tags ?? []).map(tag => tag.id.toString()));
  const defaults = (auth?.user?.default_project_tag_ids ?? [])
    .map(String)
    .filter(id => availableIds.has(id));

  // Precedence: an explicit tags param wins; the `all` sentinel means the user
  // deliberately cleared the filter; otherwise fall back to the user's defaults.
  const initialSelected = () => {
    const params = currentUrlParams();

    if (params.tags !== undefined) {
      return (Array.isArray(params.tags) ? params.tags : [params.tags]).map(String);
    }

    if (params.all) return [];

    return defaults;
  };

  const [selected, setSelected] = useState(initialSelected);
  const [saving, setSaving] = useState(false);

  useDidUpdate(() => {
    if (selected.length) reloadWithQuery({ tags: selected, all: undefined }, true);
    else reloadWithQuery({ tags: undefined, all: 1 }, true);
  }, [selected]);

  const saveAsDefault = () => {
    setSaving(true);
    router.put(
      route('account.default-project-tags.update'),
      { tags: selected },
      {
        preserveState: true,
        preserveScroll: true,
        onFinish: () => setSaving(false),
      }
    );
  };

  if (!tags?.length) return null;

  const isDirty = !sameSet(selected, defaults);

  return (
    <Group
      gap='md'
      align='center'
    >
      <Chip.Group
        multiple
        value={selected}
        onChange={setSelected}
      >
        <Group gap='xs'>
          {tags.map(tag => (
            <Chip
              key={tag.id}
              value={tag.id.toString()}
              color={tag.color}
              variant='light'
              size='sm'
            >
              {tag.name}
            </Chip>
          ))}
        </Group>
      </Chip.Group>

      {isDirty && (
        <Tooltip
          label='Save the current selection as your default filter'
          withArrow
        >
          <Button
            size='compact-xs'
            variant='subtle'
            leftSection={<IconDeviceFloppy size={14} />}
            onClick={saveAsDefault}
            loading={saving}
          >
            Save as default
          </Button>
        </Tooltip>
      )}
    </Group>
  );
}
