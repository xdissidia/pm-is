import { currentUrlParams, reloadWithoutQueryParams, reloadWithQuery } from '@/utils/route';
import { Chip, Group } from '@mantine/core';
import { useDidUpdate } from '@mantine/hooks';
import { useState } from 'react';

const initialSelected = () => {
  const tags = currentUrlParams().tags;

  if (tags === undefined) return [];

  return (Array.isArray(tags) ? tags : [tags]).map(String);
};

export default function ProjectTagFilter({ tags }) {
  const [selected, setSelected] = useState(initialSelected);

  useDidUpdate(() => {
    if (selected.length) reloadWithQuery({ tags: selected }, true);
    else reloadWithoutQueryParams({ exclude: ['tags'] });
  }, [selected]);

  if (!tags?.length) return null;

  return (
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
  );
}
