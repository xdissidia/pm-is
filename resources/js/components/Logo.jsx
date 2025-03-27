import { Center, Group, Text, rem, useComputedColorScheme } from "@mantine/core";
import { IconChartArcs } from "@tabler/icons-react";

export default function Logo(props) {
  const computedColorScheme = useComputedColorScheme();

  return (
    <Group wrap="nowrap" {...props}>
      <Center
        p={5}
        style={{ borderRadius: "100%" }}
      >
        <img src="/assets/images/pagasa-logo.png" alt="Logo" style={{ width: rem(25), height: rem(25) }} />
      </Center>
      <Text fz={20} fw={600} style={{ whiteSpace: "nowrap" }}>
        ICT - PMIS
      </Text>
    </Group>
  );
}
